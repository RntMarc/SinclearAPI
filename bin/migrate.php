#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs SQL migrations from the migrations/ directory.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? 'sinclear';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$files = glob(dirname(__DIR__) . '/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    echo "Running: " . basename($file) . "\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        continue;
    }
    $pdo->exec($sql);
    echo "  OK\n";
}

echo "Migrations complete.\n";
