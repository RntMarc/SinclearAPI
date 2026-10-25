<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;
use Sinclear\Api\Services\GameStatsService;

/**
 * Aktualisiert stuendlich den Dateisystem-Cache aller Spiele-Statistiken.
 *
 * Bei 503 (Load Shedding) der Spiele-API bricht der Lauf ab; der naechste
 * Cron-Lauf versucht es erneut.
 */
final class GameStatsRefreshTask implements CronTaskInterface
{
    private const int DEFAULT_INTERVAL_SECONDS = 3600;

    /**
     * Das Intervall folgt der konfigurierten Cache-TTL (`GAMES_CACHE_TTL`),
     * damit `nextRefreshAt` in `_meta.json` und der tatsaechliche Cron-Takt
     * uebereinstimmen.
     */
    public function __construct(
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
    ) {}

    public function getName(): string
    {
        return 'refresh_game_stats';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert den Cache der Spiele-Statistiken (Deadlock)';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds > 0
            ? $this->intervalSeconds
            : self::DEFAULT_INTERVAL_SECONDS;
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        /** @var GameStatsService $service */
        $service = $container->get(GameStatsService::class);
        $summary = $service->refreshAll();

        $logger->info('Game stats refresh abgeschlossen', [
            'games' => $summary,
        ]);
    }
}
