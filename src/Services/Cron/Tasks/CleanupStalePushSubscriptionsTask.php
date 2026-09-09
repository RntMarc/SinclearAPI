<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupStalePushSubscriptionsTask implements CronTaskInterface
{
    private const int MAX_CONSECUTIVE_FAILURES = 10;
    private const int STALE_DAYS = 90;
    private const int BATCH_SIZE = 1000;

    public function getName(): string
    {
        return 'cleanup_stale_push_subscriptions';
    }

    public function getDescription(): string
    {
        return 'Löscht tote Push-Subscriptions (dauerhaft fehlgeschlagen oder '
            . self::STALE_DAYS . ' Tage ohne Lebenszeichen)';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);

        // 1. Dauerhaft fehlgeschlagene Endpoints (403, DNS-Fehler, Timeouts, ...).
        //    Reaktiv werden hier nur 410/404 sofort gelöscht; alle übrigen
        //    Fehler zählen hoch und führen nach der Schwelle zur Löschung.
        $failedDeleted = 0;
        do {
            $stmt = $pdo->prepare(
                'DELETE FROM PushSubscription WHERE consecutiveFailures >= ? LIMIT ?'
            );
            $stmt->execute([self::MAX_CONSECUTIVE_FAILURES, self::BATCH_SIZE]);
            $deleted = $stmt->rowCount();
            $failedDeleted += $deleted;
        } while ($deleted === self::BATCH_SIZE);

        // 2. Stale Endpoints: 90 Tage kein Re-Register (Client-POST) UND kein
        //    erfolgreicher Push. Offline-Geräte erzeugen keine Failures (der
        //    Push-Service quittiert mit 201 und queued) und bleiben deshalb
        //    über die Übergangsfrist erhalten. 90 Tage = Refresh-Token-Lebensdauer:
        //    wer länger weg war, muss sich ohnehin neu einloggen und
        //    re-registriert beim nächsten Start (Upsert heilt die Subscription).
        $staleDeleted = 0;
        do {
            $stmt = $pdo->prepare(
                'DELETE FROM PushSubscription
                 WHERE lastSeenAt < DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND (lastSuccessAt IS NULL OR lastSuccessAt < DATE_SUB(NOW(), INTERVAL ? DAY))
                 LIMIT ?'
            );
            $stmt->execute([self::STALE_DAYS, self::STALE_DAYS, self::BATCH_SIZE]);
            $deleted = $stmt->rowCount();
            $staleDeleted += $deleted;
        } while ($deleted === self::BATCH_SIZE);

        $logger->info(
            "Push Subscription Cleanup: $failedDeleted dauerhaft fehlgeschlagene, "
            . "$staleDeleted veraltete Subscriptions gelöscht"
        );
    }
}
