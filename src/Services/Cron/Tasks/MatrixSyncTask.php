<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;
use Sinclear\Api\Services\MatrixSyncService;

final class MatrixSyncTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'matrix_sync';
    }

    public function getDescription(): string
    {
        return 'Synchronisiert Matrix-Accounts und Anzeigenamen über den Application Service';
    }

    public function getIntervalSeconds(): int
    {
        return 300; // 5 Minuten
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $service = $container->get(MatrixSyncService::class);

        // Phase A: Drift erkennen (selbstheilend)
        $service->reconcile();

        // Phase B: fällige Operationen verarbeiten
        $service->processDueOperations();

        $logger->info('Matrix Sync: Reconciliation + Outbox-Verarbeitung abgeschlossen');
    }
}
