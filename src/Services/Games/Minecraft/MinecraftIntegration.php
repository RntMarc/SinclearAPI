<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Minecraft;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\MinecraftPlayerRepository;
use Sinclear\Api\Repository\MinecraftStatRepository;
use Sinclear\Api\Services\Games\GameStatsIntegrationInterface;

/**
 * Minecraft-Integration fuer die gemeinsame Spiele-Infrastruktur.
 *
 * Minecraft arbeitet im Push-Modell: Die Statistiken werden vom Server-Plugin
 * an den Ingest-Endpoint gesendet und dauerhaft in der Datenbank gespeichert.
 * Es gibt daher keinen Cron-Pull (`isPushBased() === true`); die Statistiken
 * werden beim Abruf direkt aus `MinecraftStatCurrent` gelesen.
 */
final readonly class MinecraftIntegration implements GameStatsIntegrationInterface
{
    public const string SLUG = 'minecraft';

    public function __construct(
        private MinecraftProfileResolver $resolver,
        private MinecraftPlayerRepository $playerRepo,
        private MinecraftStatRepository $statRepo,
        private LoggerInterface $logger,
    ) {}

    public function isPushBased(): bool
    {
        return true;
    }

    public function refreshAll(array $accounts): void
    {
        $this->logger->debug('Minecraft stats are push-based; nothing to refresh', [
            'accounts' => count($accounts),
        ]);
    }

    public function getCachedStats(string $accountId): ?array
    {
        $uuid = $accountId;
        $player = $this->playerRepo->findByUuid($uuid);
        $rows = $this->statRepo->getCurrentStatsForPlayer($uuid);

        if ($rows === []) {
            return null;
        }

        return $this->buildStats($uuid, is_array($player) ? ($player['name'] ?? null) : null, $rows);
    }

    public function getCacheMeta(): array
    {
        $latest = $this->statRepo->latestCollectedAt();

        return [
            'lastRefreshedAt' => $latest !== null ? self::isoUtc($latest) : null,
            'nextRefreshAt' => null,
            'intervalSeconds' => 0,
        ];
    }

    public function resolveProfile(string $input): array
    {
        return $this->resolver->resolve($input);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildStats(string $uuid, ?string $name, array $rows): array
    {
        /** @var array<int, array{serverId: string, world: string, collectedAt: string|null, stats: array<string, array<string, int>>}> $sources */
        $sources = [];
        $latest = null;

        foreach ($rows as $row) {
            $sourceId = (int) $row['sourceId'];
            if (!isset($sources[$sourceId])) {
                $sources[$sourceId] = [
                    'serverId' => (string) $row['serverId'],
                    'world' => (string) $row['world'],
                    'collectedAt' => null,
                    'stats' => [],
                ];
            }

            $category = (string) $row['category'];
            $stat = (string) $row['stat'];
            $sources[$sourceId]['stats'][$category][$stat] = (int) $row['value'];

            $at = (string) $row['collectedAt'];
            if ($sources[$sourceId]['collectedAt'] === null || $at > $sources[$sourceId]['collectedAt']) {
                $sources[$sourceId]['collectedAt'] = $at;
            }
            if ($latest === null || $at > $latest) {
                $latest = $at;
            }
        }

        $sourceList = array_map(
            static fn (array $source): array => [
                'serverId' => $source['serverId'],
                'world' => $source['world'],
                'collectedAt' => self::isoUtc($source['collectedAt']),
                'stats' => $source['stats'],
            ],
            array_values($sources),
        );

        usort(
            $sourceList,
            static fn (array $a, array $b): int => [$a['serverId'], $a['world']] <=> [$b['serverId'], $b['world']],
        );

        return [
            'accountId' => $uuid,
            'player' => [
                'uuid' => $uuid,
                'name' => $name,
            ],
            'sources' => $sourceList,
            'syncedAt' => $latest !== null ? self::isoUtc($latest) : null,
        ];
    }

    private static function isoUtc(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
