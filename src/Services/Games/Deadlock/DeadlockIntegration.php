<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Deadlock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Games\GameStatsCache;
use Sinclear\Api\Services\Games\GameStatsIntegrationInterface;

/**
 * Deadlock-Integration (Valve) ueber die inoffizielle Deadlock API.
 *
 * Normalisierte Cache-Struktur pro Account:
 * {
 *   "accountId": "179673173",
 *   "rank": { "badge": 0, "rank": 0, "subrank": 0, "lastMatch": null },
 *   "heroStats": [
 *     { "heroId": 2, "matchesPlayed": 2, "wins": 0, "losses": 2, "winRate": 0.0, ... }
 *   ],
 *   "matchHistory": [
 *     { "matchId": 124029819, "heroId": 87, "startTime": "2026-...", ... }
 *   ],
 *   "syncedAt": "2026-10-25T14:00:00Z"
 * }
 */
final readonly class DeadlockIntegration implements GameStatsIntegrationInterface
{
    public const string SLUG = 'deadlock';

    private const string STEAM_ID64_OFFSET = '76561197960265728';
    private const int MAX_MATCHES = 20;

    public function __construct(
        private DeadlockClient $client,
        private GameStatsCache $cache,
        private LoggerInterface $logger,
    ) {}

    public function refreshAll(array $accounts): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $accountIds = array_values(array_unique(array_map(
            static fn (array $account): string => (string) $account['accountId'],
            $accounts,
        )));

        $ranks = [];
        $heroStats = [];

        if ($accountIds !== []) {
            try {
                foreach ($this->client->rankBatch($accountIds) as $row) {
                    $ranks[(string) ($row['account_id'] ?? '')] = $row;
                }
            } catch (DeadlockApiException $e) {
                // Load Shedding bricht den gesamten Lauf ab; andere Fehler
                // werden protokolliert, damit die uebrigen Daten noch in den
                // Cache geschrieben werden.
                if ($e->status === 503) {
                    throw $e;
                }

                $this->logger->warning('Deadlock rank batch unavailable', [
                    'status' => $e->status,
                    'accountCount' => count($accountIds),
                ]);
            }

            try {
                foreach ($this->client->heroStats($accountIds) as $row) {
                    $heroStats[(string) ($row['account_id'] ?? '')][] = $row;
                }
            } catch (DeadlockApiException $e) {
                if ($e->status === 503) {
                    throw $e;
                }

                $this->logger->warning('Deadlock hero stats batch unavailable', [
                    'status' => $e->status,
                    'accountCount' => count($accountIds),
                ]);
            }
        }

        foreach ($accounts as $account) {
            $accountId = (string) $account['accountId'];
            $matches = [];

            try {
                $matches = $this->client->matchHistory($accountId);
            } catch (DeadlockApiException $e) {
                // Load Shedding bricht den gesamten Lauf ab; der naechste
                // Cron-Lauf versucht es erneut.
                if ($e->status === 503) {
                    throw $e;
                }

                $this->logger->warning('Deadlock match history unavailable', [
                    'accountId' => $accountId,
                    'status' => $e->status,
                ]);
            }

            $this->cache->write(self::SLUG, $accountId, [
                'accountId' => $accountId,
                'rank' => $this->normalizeRank($ranks[$accountId] ?? null),
                'heroStats' => array_map(
                    $this->normalizeHeroStats(...),
                    $heroStats[$accountId] ?? [],
                ),
                'matchHistory' => $this->normalizeMatchHistory($matches),
                'syncedAt' => self::isoUtc($now),
            ]);
        }

        $nextRefresh = $now->modify('+' . $this->cache->ttl() . ' seconds');
        $this->cache->writeMeta(self::SLUG, self::isoUtc($now), self::isoUtc($nextRefresh));

        $this->logger->info('Deadlock stats refreshed', [
            'accounts' => count($accounts),
        ]);
    }

    public function getCachedStats(string $accountId): ?array
    {
        return $this->cache->read(self::SLUG, $accountId);
    }

    public function getCacheMeta(): array
    {
        return $this->cache->meta(self::SLUG);
    }

    public function resolveProfile(string $accountId): array
    {
        try {
            $profiles = $this->client->steamProfiles([$accountId], true);
        } catch (DeadlockApiException $e) {
            if ($e->status === 400) {
                throw new InvalidArgumentException('account_not_found');
            }

            // Rate-Limit, Load Shedding oder Netzwerkfehler: die ID ist nicht
            // als ungueltig zu werten, sondern die API ist gerade nicht
            // verfuegbar.
            throw new InvalidArgumentException('game_api_unavailable');
        }

        foreach ($profiles as $profile) {
            if ((string) ($profile['account_id'] ?? '') !== $accountId) {
                continue;
            }

            return [
                'displayName' => isset($profile['personaname']) ? (string) $profile['personaname'] : null,
                'avatarUrl' => isset($profile['avatarfull'])
                    ? (string) $profile['avatarfull']
                    : (isset($profile['avatar']) ? (string) $profile['avatar'] : null),
                'profile' => [
                    'steamId64' => self::steamId64($accountId),
                    'profileUrl' => isset($profile['profileurl']) ? (string) $profile['profileurl'] : null,
                ],
            ];
        }

        throw new InvalidArgumentException('account_not_found');
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function normalizeRank(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $lastMatch = $row['last_match'] ?? null;

        return [
            'badge' => (int) ($row['badge'] ?? 0),
            'rank' => (int) ($row['rank'] ?? 0),
            'subrank' => (int) ($row['subrank'] ?? 0),
            'lastMatch' => is_array($lastMatch) ? [
                'matchId' => isset($lastMatch['match_id']) ? (string) $lastMatch['match_id'] : null,
                'startTime' => isset($lastMatch['start_time']) && (int) $lastMatch['start_time'] > 0
                    ? self::isoUtc((new DateTimeImmutable('@' . (int) $lastMatch['start_time']))->setTimezone(new DateTimeZone('UTC')))
                    : null,
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeHeroStats(array $row): array
    {
        $matchesPlayed = (int) ($row['matches_played'] ?? 0);
        $wins = (int) ($row['wins'] ?? 0);

        return [
            'heroId' => (int) ($row['hero_id'] ?? 0),
            'matchesPlayed' => $matchesPlayed,
            'wins' => $wins,
            'losses' => max(0, $matchesPlayed - $wins),
            'winRate' => $matchesPlayed > 0 ? round($wins / $matchesPlayed, 4) : 0.0,
            'lastPlayed' => isset($row['last_played']) && (int) $row['last_played'] > 0
                ? self::isoUtc((new DateTimeImmutable('@' . (int) $row['last_played']))->setTimezone(new DateTimeZone('UTC')))
                : null,
            'timePlayedSeconds' => (int) ($row['time_played'] ?? 0),
            'endingLevel' => isset($row['ending_level']) ? (float) $row['ending_level'] : null,
            'kills' => (int) ($row['kills'] ?? 0),
            'deaths' => (int) ($row['deaths'] ?? 0),
            'assists' => (int) ($row['assists'] ?? 0),
            'killsPerMin' => isset($row['kills_per_min']) ? (float) $row['kills_per_min'] : null,
            'deathsPerMin' => isset($row['deaths_per_min']) ? (float) $row['deaths_per_min'] : null,
            'assistsPerMin' => isset($row['assists_per_min']) ? (float) $row['assists_per_min'] : null,
            'networthPerMin' => isset($row['networth_per_min']) ? (float) $row['networth_per_min'] : null,
            'lastHitsPerMin' => isset($row['last_hits_per_min']) ? (float) $row['last_hits_per_min'] : null,
            'deniesPerMin' => isset($row['denies_per_min']) ? (float) $row['denies_per_min'] : null,
            'damagePerMin' => isset($row['damage_per_min']) ? (float) $row['damage_per_min'] : null,
            'damageTakenPerMin' => isset($row['damage_taken_per_min']) ? (float) $row['damage_taken_per_min'] : null,
            'accuracy' => isset($row['accuracy']) ? (float) $row['accuracy'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $matches
     * @return list<array<string, mixed>>
     */
    private function normalizeMatchHistory(array $matches): array
    {
        usort(
            $matches,
            static fn (array $a, array $b): int => (int) ($b['start_time'] ?? 0) <=> (int) ($a['start_time'] ?? 0),
        );

        $matches = array_slice($matches, 0, self::MAX_MATCHES);

        return array_map(static function (array $match): array {
            $startTime = (int) ($match['start_time'] ?? 0);

            return [
                'matchId' => isset($match['match_id']) ? (string) $match['match_id'] : null,
                'heroId' => (int) ($match['hero_id'] ?? 0),
                'heroLevel' => (int) ($match['hero_level'] ?? 0),
                'startTime' => $startTime > 0
                    ? self::isoUtc((new DateTimeImmutable('@' . $startTime))->setTimezone(new DateTimeZone('UTC')))
                    : null,
                'gameMode' => (int) ($match['game_mode'] ?? 0),
                'matchMode' => (int) ($match['match_mode'] ?? 0),
                'playerTeam' => (int) ($match['player_team'] ?? 0),
                'kills' => (int) ($match['player_kills'] ?? 0),
                'deaths' => (int) ($match['player_deaths'] ?? 0),
                'assists' => (int) ($match['player_assists'] ?? 0),
                'netWorth' => (int) ($match['net_worth'] ?? 0),
                'lastHits' => (int) ($match['last_hits'] ?? 0),
                'denies' => (int) ($match['denies'] ?? 0),
                'matchDurationSeconds' => (int) ($match['match_duration_s'] ?? 0),
                'matchResult' => (int) ($match['match_result'] ?? 0),
                'playerMatchOutcome' => (int) ($match['player_match_outcome'] ?? 0),
                'abandonedTimeSeconds' => (int) ($match['abandoned_time_s'] ?? 0),
            ];
        }, $matches);
    }

    private static function isoUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function steamId64(string $accountId): string
    {
        if (!ctype_digit($accountId)) {
            return '';
        }

        return (string) ((int) $accountId + (int) self::STEAM_ID64_OFFSET);
    }
}
