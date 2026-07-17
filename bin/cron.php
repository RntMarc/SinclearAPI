#!/usr/bin/env php
<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Sinclear\Api\Services\Cron\CronScheduler;
use Sinclear\Api\Services\Cron\Tasks\CleanupExpiredOtpTokensTask;
use Sinclear\Api\Services\Cron\Tasks\CleanupOldLocationSharingTask;
use Sinclear\Api\Services\Cron\Tasks\CleanupOldNotificationsTask;
use Sinclear\Api\Services\Cron\Tasks\RefreshPublicTransportJourneysTask;
use Sinclear\Api\Services\Cron\Tasks\RefreshPublicTransportStationsTask;

require_once __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);

$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions($rootDir . '/config/dependencies.php');

$container = $containerBuilder->build();

$scheduler = $container->get(CronScheduler::class);

// Tasks registrieren
$scheduler->register(new CleanupExpiredOtpTokensTask());
$scheduler->register(new CleanupOldNotificationsTask());
$scheduler->register(new CleanupOldLocationSharingTask());
$scheduler->register(new RefreshPublicTransportStationsTask());
$scheduler->register(new RefreshPublicTransportJourneysTask());

// Ausstehende Tasks ausführen
$results = $scheduler->runDueTasks($container);

if ($results === []) {
    echo "Keine Tasks ausstehend.\n";
    exit(0);
}

foreach ($results as $name => $result) {
    $status = $result['status'];
    $detail = $status === 'success'
        ? "erfolgreich ({$result['durationMs']}ms)"
        : "fehlgeschlagen: {$result['error']}";
    echo "[$status] $name — $detail\n";
}

exit(0);
