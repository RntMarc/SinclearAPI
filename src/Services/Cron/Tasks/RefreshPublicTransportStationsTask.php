<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class RefreshPublicTransportStationsTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'pt_stations_refresh';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert den lokalen Stationen-Cache mit Daten von db-stations';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $service = $container->get(\Sinclear\Api\Services\PublicTransportService::class);
        $count = $service->refreshAllStations();

        $logger->info("PT Stations Refresh: $count Stationen aktualisiert");
    }
}
