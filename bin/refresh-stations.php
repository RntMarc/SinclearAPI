#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Sinclear\Api\Cli;

use DI\ContainerBuilder;
use Sinclear\Api\Services\PublicTransportService;

require_once __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);

$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions($rootDir . '/config/dependencies.php');

if (!isset($_ENV['APP_DEBUG']) || !filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    $containerBuilder->enableCompilation($rootDir . '/var/cache');
}

$container = $containerBuilder->build();

$service = $container->get(PublicTransportService::class);

echo "Lade Stationen von db-stations...\n";
$count = $service->refreshAllStations();

echo "$count Stationen erfolgreich geladen.\n";
exit(0);
