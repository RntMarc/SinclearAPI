<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;
use Sinclear\Api\Services\PtService;

final class PtRefreshStaleLegsTask implements CronTaskInterface
{
    private const int BATCH_SIZE = 8;
    private const int BATCH_DELAY_SECONDS = 2;
    private const int MAX_AGE_MINUTES = 5;

    public function getName(): string
    {
        return 'pt_refresh_stale_legs';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert veraltete PT-Legs mit Echtzeitdaten von Transitious';
    }

    public function getIntervalSeconds(): int
    {
        return 300; // 5 Minuten
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $ptService = $container->get(PtService::class);

        $staleLegs = $this->findStaleLegs($container, self::MAX_AGE_MINUTES);

        if ($staleLegs === []) {
            $logger->info('PT Refresh: Keine veralteten Legs gefunden');
            return;
        }

        $total = count($staleLegs);
        $batches = array_chunk($staleLegs, self::BATCH_SIZE);
        $refreshed = 0;
        $failed = 0;

        $logger->info("PT Refresh: $total stale Legs in " . count($batches) . " Batches");

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $leg) {
                try {
                    $success = $ptService->refreshSingleLeg($leg['id']);
                    if ($success) {
                        $refreshed++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $logger->warning("PT Refresh: Fehler bei Leg {$leg['id']}: {$e->getMessage()}");
                }
            }

            // Pause zwischen Batches (nach dem letzten Batch nicht nötig)
            if ($batchIndex < count($batches) - 1) {
                sleep(self::BATCH_DELAY_SECONDS);
            }
        }

        $logger->info("PT Refresh abgeschlossen: $refreshed aktualisiert, $failed fehlgeschlagen");
    }

    private function findStaleLegs(ContainerInterface $container, int $maxAgeMinutes): array
    {
        $pdo = $container->get(\PDO::class);
        $stmt = $pdo->prepare(
            "SELECT l.id, l.tripId, l.plannedDeparture
             FROM PtLeg l
             WHERE l.tripId IS NOT NULL
               AND l.cancelled = 0
               AND (l.lastCheckedAt IS NULL OR l.lastCheckedAt < DATE_SUB(NOW(), INTERVAL ? MINUTE))
             ORDER BY l.lastCheckedAt ASC"
        );
        $stmt->execute([$maxAgeMinutes]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
