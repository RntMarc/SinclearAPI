<?php

declare(strict_types=1);

use DI\ContainerBuilder;

$rootDir = dirname(__DIR__);

date_default_timezone_set('UTC');

$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions($rootDir . '/config/dependencies.php');

if (!isset($_ENV['APP_DEBUG']) || !filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    $containerBuilder->enableCompilation($rootDir . '/var/cache');
}

return $containerBuilder->build();
