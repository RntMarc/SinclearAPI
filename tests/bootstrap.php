<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$_ENV['DB_HOST'] = $_ENV['TEST_DB_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1';
$_ENV['DB_PORT'] = $_ENV['TEST_DB_PORT'] ?? $_ENV['DB_PORT'] ?? '3306';
$_ENV['DB_NAME'] = $_ENV['TEST_DB_NAME'] ?? $_ENV['DB_NAME'] ?? 'sinclear_test';
$_ENV['DB_USER'] = $_ENV['TEST_DB_USER'] ?? $_ENV['DB_USER'] ?? 'root';
$_ENV['DB_PASSWORD'] = $_ENV['TEST_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';
