<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupOldLocationSharingTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_location_sharing';
    }

    public function getDescription(): string
    {
        return 'Bereinigt alte Location-Sharing-Sessions und zugehörige Daten';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);

        $staleSessionQuery = 'SELECT id FROM LocationSharingSession WHERE (
            SELECT COALESCE(MAX(l.recordedAt), s.createdAt)
            FROM LocationSharingLocation l WHERE l.sessionId = s.id
        ) < DATE_SUB(NOW(), INTERVAL 7 DAY)';

        $staleIds = $pdo->query($staleSessionQuery)->fetchAll(PDO::FETCH_COLUMN);
        if ($staleIds === []) {
            $logger->info('Location Sharing Cleanup: Keine veralteten Sessions gefunden');
            return;
        }

        $placeholders = implode(',', array_fill(0, count($staleIds), '?'));

        $deletedLocations = $pdo->prepare("DELETE FROM LocationSharingLocation WHERE sessionId IN ($placeholders)");
        $deletedLocations->execute($staleIds);

        $deletedRecipients = $pdo->prepare("DELETE FROM LocationSharingRecipient WHERE sessionId IN ($placeholders)");
        $deletedRecipients->execute($staleIds);

        $deletedSessions = $pdo->prepare("DELETE FROM LocationSharingSession WHERE id IN ($placeholders)");
        $deletedSessions->execute($staleIds);

        $count = count($staleIds);
        $logger->info("Location Sharing Cleanup: $count Sessions gelöscht");
    }
}
