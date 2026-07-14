<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class RefreshPublicTransportJourneysTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'pt_journeys_refresh';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert Verspätungen/Ausfälle für offene Fahrten';
    }

    public function getIntervalSeconds(): int
    {
        return 900; // 15 Minuten
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $service = $container->get(\Sinclear\Api\Services\PublicTransportService::class);
        $updated = $service->refreshStaleJourneys(15);

        $logger->info("PT Journeys Refresh: $updated Fahrten aktualisiert");
    }
}
