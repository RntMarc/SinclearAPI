<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupOldNotificationsTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_notifications';
    }

    public function getDescription(): string
    {
        return 'Löscht Notifications älter als 30 Tage';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);
        $stmt = $pdo->prepare('DELETE FROM Notification WHERE createdAt < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $stmt->execute();
        $count = $stmt->rowCount();

        $logger->info("Notification Cleanup: $count Einträge gelöscht");
    }
}
