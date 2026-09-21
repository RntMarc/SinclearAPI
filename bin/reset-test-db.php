#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$dbHost = $_ENV['TEST_DB_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['TEST_DB_PORT'] ?? $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['TEST_DB_NAME'] ?? $_ENV['DB_NAME'] ?? 'sinclear_test';
$dbUser = $_ENV['TEST_DB_USER'] ?? $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['TEST_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';

try {
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
} catch (\PDOException $e) {
    fwrite(STDERR, "DB-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

$db->exec('SET FOREIGN_KEY_CHECKS = 0');

$stmt = $db->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    echo "Test-Datenbank '$dbName' ist bereits leer.\n";
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    exit(0);
}

$count = 0;
foreach ($tables as $table) {
    $db->exec("DROP TABLE IF EXISTS `$table`");
    $count++;
}

$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "$count Tabellen aus Test-Datenbank '$dbName' entfernt.\n";
