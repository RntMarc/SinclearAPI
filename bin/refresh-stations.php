#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$containerBuilder = new DI\ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/dependencies.php');

$container = $containerBuilder->build();

$service = $container->get(Sinclear\Api\Services\PublicTransportService::class);

echo "Lade Stationen von db-stations...\n";
$count = $service->refreshAllStations();

echo "$count Stationen erfolgreich geladen.\n";
exit(0);
