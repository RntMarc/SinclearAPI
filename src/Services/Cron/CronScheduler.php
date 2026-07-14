<?php

namespace Sinclear\Api\Services\Cron;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class CronScheduler
{
    private PDO $pdo;
    private LoggerInterface $logger;

    /** @var CronTaskInterface[] */
    private array $tasks = [];

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function register(CronTaskInterface $task): void
    {
        $this->tasks[$task->getName()] = $task;
    }

    public function getDueTasks(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $due = [];

        foreach ($this->tasks as $task) {
            $lastRunAt = $this->getLastRunAt($task->getName());
            $interval = $task->getIntervalSeconds();

            if ($lastRunAt === null) {
                $due[] = $task;
                continue;
            }

            $diff = $now->getTimestamp() - $lastRunAt->getTimestamp();
            if ($diff >= $interval) {
                $due[] = $task;
            }
        }

        return $due;
    }

    public function runDueTasks(ContainerInterface $container): array
    {
        $dueTasks = $this->getDueTasks();

        if ($dueTasks === []) {
            $this->logger->info('Cron: Keine Tasks ausstehend');
            return [];
        }

        $results = [];

        foreach ($dueTasks as $task) {
            $name = $task->getName();
            $this->logger->info("Cron: Starte Task '$name'");
            $startMs = (int) (microtime(true) * 1000);

            try {
                $task->execute($container, $this->logger);
                $durationMs = (int) (microtime(true) * 1000) - $startMs;
                $this->recordSuccess($name, $durationMs);
                $results[$name] = ['status' => 'success', 'durationMs' => $durationMs];
                $this->logger->info("Cron: Task '$name' abgeschlossen in {$durationMs}ms");
            } catch (\Throwable $e) {
                $durationMs = (int) (microtime(true) * 1000) - $startMs;
                $this->recordFailure($name, $durationMs, $e->getMessage());
                $results[$name] = ['status' => 'failed', 'error' => $e->getMessage()];
                $this->logger->error("Cron: Task '$name' fehlgeschlagen: {$e->getMessage()}");
            }
        }

        return $results;
    }

    private function getLastRunAt(string $taskName): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT lastRunAt FROM CronSchedule WHERE taskName = ?');
        $stmt->execute([$taskName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || $row['lastRunAt'] === null) {
            return null;
        }

        return new \DateTimeImmutable($row['lastRunAt'], new \DateTimeZone('UTC'));
    }

    private function recordSuccess(string $taskName, int $durationMs): void
    {
        $now = date('Y-m-d H:i:s');
        $nowMs = date('Y-m-d H:i:s.') . str_pad((int) (microtime(true) * 1000 % 1000), 3, '0', STR_PAD_LEFT);
        $stmt = $this->pdo->prepare('
            INSERT INTO CronSchedule (taskName, lastRunAt, lastDurationMs, lastStatus, createdAt)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE lastRunAt = ?, lastDurationMs = ?, lastStatus = ?, lastError = NULL
        ');
        $stmt->execute([$taskName, $now, $durationMs, 'success', $now, $now, $durationMs, 'success']);
    }

    private function recordFailure(string $taskName, int $durationMs, string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('
            INSERT INTO CronSchedule (taskName, lastRunAt, lastDurationMs, lastStatus, lastError, createdAt)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE lastRunAt = ?, lastDurationMs = ?, lastStatus = ?, lastError = ?
        ');
        $stmt->execute([$taskName, $now, $durationMs, 'failed', $error, $now, $now, $durationMs, 'failed', $error]);
    }
}
