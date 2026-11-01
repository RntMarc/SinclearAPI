<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupArcadeSessionsTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_arcade_sessions';
    }

    public function getDescription(): string
    {
        return 'Löscht abgelaufene und widerrufene Arcade-Sessions';
    }

    public function getIntervalSeconds(): int
    {
        return 3600; // 1 Stunde
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);
        $deleted = $pdo->exec(
            'DELETE FROM ArcadeSession WHERE expiresAt < NOW(3) OR revokedAt IS NOT NULL'
        );

        $count = $deleted === false ? 0 : $deleted;
        $logger->info("Arcade Session Cleanup: $count Einträge gelöscht");
    }
}
