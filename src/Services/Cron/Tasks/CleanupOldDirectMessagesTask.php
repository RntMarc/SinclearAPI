<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupOldDirectMessagesTask implements CronTaskInterface
{
    private const int RETENTION_DAYS = 90;
    private const int BATCH_SIZE = 1000;

    public function getName(): string
    {
        return 'cleanup_direct_messages';
    }

    public function getDescription(): string
    {
        return 'Löscht Direct Messages älter als 90 Tage (gebatcht)';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);

        $totalDeleted = 0;

        // Batch-delete old messages to avoid long locks
        do {
            $stmt = $pdo->prepare(
                'DELETE FROM DirectMessage WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?'
            );
            $stmt->execute([self::RETENTION_DAYS, self::BATCH_SIZE]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;
        } while ($deleted === self::BATCH_SIZE);

        // Cleanup orphaned conversations (no messages, older than 1 day)
        $stmt = $pdo->prepare(
            'DELETE FROM ChatConversation
             WHERE id NOT IN (SELECT DISTINCT conversationId FROM DirectMessage)
               AND createdAt < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $stmt->execute();
        $orphanedDeleted = $stmt->rowCount();

        // Cleanup expired presence entries
        $pdo->exec('DELETE FROM ChatPresence WHERE activeUntil < NOW(3)');

        // Cleanup expired typing indicators
        $pdo->exec('DELETE FROM ChatTyping WHERE expiresAt < NOW(3)');

        $logger->info("Chat Cleanup: $totalDeleted Nachrichten gelöscht, $orphanedDeleted verwaiste Konversationen entfernt");
    }
}
