<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use InvalidArgumentException;
use Sinclear\Api\Repository\GameAccountRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\Games\GameRegistry;

/**
 * Orchestriert Spiele-Statistiken: Cache lesen, Metadaten liefern und die
 * Aktualisierung ueber die jeweilige Integration anstossen.
 */
final readonly class GameStatsService
{
    private const int MAX_BATCH_USERS = 100;

    public function __construct(
        private GameRegistry $registry,
        private GameAccountRepository $repo,
        private UserRepository $userRepo,
    ) {}

    /**
     * @return array<string, mixed>|null null, wenn der Nutzer nicht existiert
     *                                      oder keinen Account fuer das Spiel
     *                                      verknuepft hat
     */
    public function getStats(string $game, string $userId): ?array
    {
        $integration = $this->registry->get($game);

        // Wie in der Batch-Abfrage: nur Nutzer ausliefern, die noch in der
        // User-Tabelle existieren (kein FK in GameAccount).
        if ($this->userRepo->findById($userId) === null) {
            return null;
        }

        $account = $this->repo->findByUserIdAndGame($userId, $game);

        if ($account === null) {
            return null;
        }

        $meta = $integration->getCacheMeta();

        return [
            'game' => $game,
            'userId' => $userId,
            'accountId' => $account['accountId'],
            'stats' => $integration->getCachedStats($account['accountId']),
            'lastRefreshedAt' => $meta['lastRefreshedAt'],
            'nextRefreshAt' => $meta['nextRefreshAt'],
        ];
    }

    /**
     * Batch-Abfrage. Liefert nur Beyond-Nutzer, die in der DB existieren und
     * einen verknuepften Account fuer das Spiel besitzen.
     *
     * @param list<string> $userIds
     * @return list<array<string, mixed>>
     */
    public function getBatch(string $game, array $userIds): array
    {
        $integration = $this->registry->get($game);

        $userIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $userIds),
            static fn (string $id): bool => $id !== '',
        )));

        if (count($userIds) > self::MAX_BATCH_USERS) {
            throw new InvalidArgumentException('too_many_user_ids');
        }

        $accounts = $this->repo->listByGameAndUserIds($game, $userIds);
        $meta = $integration->getCacheMeta();

        return array_map(
            fn (array $account): array => [
                'game' => $game,
                'userId' => $account['userId'],
                'accountId' => $account['accountId'],
                'stats' => $integration->getCachedStats($account['accountId']),
                'lastRefreshedAt' => $meta['lastRefreshedAt'],
                'nextRefreshAt' => $meta['nextRefreshAt'],
            ],
            $accounts,
        );
    }

    /**
     * @return array{game: string, lastRefreshedAt: string|null, nextRefreshAt: string|null, intervalSeconds: int}
     */
    public function getCacheMeta(string $game): array
    {
        $meta = $this->registry->get($game)->getCacheMeta();

        return [
            'game' => $game,
            'lastRefreshedAt' => $meta['lastRefreshedAt'],
            'nextRefreshAt' => $meta['nextRefreshAt'],
            'intervalSeconds' => $meta['intervalSeconds'],
        ];
    }

    /**
     * Aktualisiert den Cache fuer alle Spiele und alle verknuepften Accounts.
     *
     * @return array<string, int> Anzahl aktualisierter Accounts je Spiel
     */
    public function refreshAll(): array
    {
        $summary = [];

        foreach ($this->registry->slugs() as $game) {
            $accounts = $this->repo->listByGame($game);
            $this->registry->get($game)->refreshAll($accounts);

            $accountIds = array_map(
                static fn (array $account): string => (string) $account['accountId'],
                $accounts,
            );
            $this->repo->markSynced($game, $accountIds);

            $summary[$game] = count($accounts);
        }

        return $summary;
    }
}
