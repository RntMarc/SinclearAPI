<?php

namespace Sinclear\Api\Services\Cron;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class CronScheduler
{
    /**
     * Toleranz fuer den Faelligkeitsvergleich. Cron startet zwar punktgenau,
     * der PHP-Prozess braucht aber einige Millisekunden bis zur Pruefung.
     * Ohne Toleranz wuerde ein Task, dessen letzter Start knapp hinter der
     * Taktgrenze liegt (z. B. 10:00:00.037), beim Tick 11:00:00.005 noch als
     * "nicht faellig" gelten und erst um 11:10 laufen — und danach dauerhaft
     * um 10 Minuten versetzt bleiben.
     */
    private const int DUE_TOLERANCE_SECONDS = 2;

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
        return $this->getDueTasksAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Ermittelt die faelligen Tasks bezogen auf einen festen Zykluszeitpunkt.
     *
     * @return CronTaskInterface[]
     */
    private function getDueTasksAt(\DateTimeImmutable $now): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            $lastRunAt = $this->getLastRunAt($task->getName());
            $interval = $task->getIntervalSeconds();

            if ($lastRunAt === null) {
                $due[] = $task;
                continue;
            }

            $diff = $now->getTimestamp() - $lastRunAt->getTimestamp();
            if ($diff >= $interval - self::DUE_TOLERANCE_SECONDS) {
                $due[] = $task;
            }
        }

        return $due;
    }

    public function runDueTasks(ContainerInterface $container): array
    {
        // Ein Zeitstempel fuer den gesamten Zyklus: Er bestimmt sowohl die
        // Faelligkeitspruefung als auch den in CronSchedule gespeicherten
        // Startzeitpunkt aller Tasks dieses Laufs. Dadurch verschiebt kein
        // langsam laufender Vorgaenger-Task den Takt der nachfolgenden Tasks.
        $cycleStartedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dueTasks = $this->getDueTasksAt($cycleStartedAt);

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
                $this->recordSuccess($name, $cycleStartedAt, $durationMs);
                $results[$name] = ['status' => 'success', 'durationMs' => $durationMs];
                $this->logger->info("Cron: Task '$name' abgeschlossen in {$durationMs}ms");
            } catch (\Throwable $e) {
                $durationMs = (int) (microtime(true) * 1000) - $startMs;
                $this->recordFailure($name, $cycleStartedAt, $durationMs, $e->getMessage());
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

    /**
     * Speichert den Startzeitpunkt des Cron-Zyklus (nicht das Laufende), damit
     * sich der naechste Ausfuehrungszeitpunkt am Takt orientiert und nicht
     * durch die Laufzeit verzoegert wird.
     */
    private function recordSuccess(string $taskName, \DateTimeImmutable $cycleStartedAt, int $durationMs): void
    {
        $startedAtSql = self::toSqlTimestamp($cycleStartedAt);
        $stmt = $this->pdo->prepare('
            INSERT INTO CronSchedule (taskName, lastRunAt, lastDurationMs, lastStatus, createdAt)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE lastRunAt = ?, lastDurationMs = ?, lastStatus = ?, lastError = NULL
        ');
        $stmt->execute([$taskName, $startedAtSql, $durationMs, 'success', $startedAtSql, $startedAtSql, $durationMs, 'success']);
    }

    private function recordFailure(string $taskName, \DateTimeImmutable $cycleStartedAt, int $durationMs, string $error): void
    {
        $startedAtSql = self::toSqlTimestamp($cycleStartedAt);
        $stmt = $this->pdo->prepare('
            INSERT INTO CronSchedule (taskName, lastRunAt, lastDurationMs, lastStatus, lastError, createdAt)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE lastRunAt = ?, lastDurationMs = ?, lastStatus = ?, lastError = ?
        ');
        $stmt->execute([$taskName, $startedAtSql, $durationMs, 'failed', $error, $startedAtSql, $startedAtSql, $durationMs, 'failed', $error]);
    }

    /**
     * Formatiert einen Zeitpunkt als UTC-SQL-Timestamp mit Millisekunden
     * (passend zu DATETIME(3)).
     */
    private static function toSqlTimestamp(\DateTimeImmutable $timestamp): string
    {
        return $timestamp
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }
}
