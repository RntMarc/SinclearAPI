<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;
use Sinclear\Api\Services\PollNotificationService;

/**
 * Schließt abgelaufene Umfragen und erinnert Teilnehmer kurz vor der Frist.
 */
final class PollDeadlineTask implements CronTaskInterface
{
    private const int REMINDER_WINDOW_HOURS = 24;

    public function getName(): string
    {
        return 'polls_deadline';
    }

    public function getDescription(): string
    {
        return 'Schließt abgelaufene Umfragen und erinnert Teilnehmer vor der Deadline';
    }

    public function getIntervalSeconds(): int
    {
        return 3600; // stündlich
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);
        $notificationService = $container->get(PollNotificationService::class);

        $this->closeExpiredPolls($pdo, $notificationService, $logger);
        $this->sendDeadlineReminders($pdo, $notificationService, $logger);
    }

    private function closeExpiredPolls(PDO $pdo, PollNotificationService $notificationService, LoggerInterface $logger): void
    {
        $stmt = $pdo->prepare(
            "SELECT p.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM Poll p
             JOIN User u ON u.id = p.creatorId
             WHERE p.status = 'open' AND p.closesAt IS NOT NULL AND p.closesAt < NOW(3)"
        );
        $stmt->execute();
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($polls === []) {
            $logger->info('Poll Deadline: Keine abgelaufenen Umfragen gefunden');
            return;
        }

        $update = $pdo->prepare("UPDATE Poll SET status = 'closed', updatedAt = NOW(3) WHERE id = ? AND status = 'open'");

        foreach ($polls as $poll) {
            $update->execute([$poll['id']]);
            try {
                $notificationService->notifyClosed($poll);
            } catch (\Throwable $e) {
                $logger->warning('Poll Deadline: Schließen-Benachrichtigung fehlgeschlagen', [
                    'pollId' => $poll['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $logger->info('Poll Deadline: ' . count($polls) . ' Umfragen geschlossen');
    }

    private function sendDeadlineReminders(PDO $pdo, PollNotificationService $notificationService, LoggerInterface $logger): void
    {
        $stmt = $pdo->prepare(
            "SELECT p.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM Poll p
             JOIN User u ON u.id = p.creatorId
             WHERE p.status = 'open'
               AND p.closesAt IS NOT NULL
               AND p.reminderSentAt IS NULL
               AND p.closesAt BETWEEN NOW(3) AND DATE_ADD(NOW(3), INTERVAL " . self::REMINDER_WINDOW_HOURS . " HOUR)"
        );
        $stmt->execute();
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($polls === []) {
            $logger->info('Poll Deadline: Keine Erinnerungen fällig');
            return;
        }

        $markSent = $pdo->prepare('UPDATE Poll SET reminderSentAt = NOW(3) WHERE id = ?');

        foreach ($polls as $poll) {
            try {
                $notificationService->notifyDeadlineReminder($poll);
            } catch (\Throwable $e) {
                $logger->warning('Poll Deadline: Erinnerung fehlgeschlagen', [
                    'pollId' => $poll['id'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $markSent->execute([$poll['id']]);
        }

        $logger->info('Poll Deadline: ' . count($polls) . ' Umfrage(n) erinnert');
    }
}
