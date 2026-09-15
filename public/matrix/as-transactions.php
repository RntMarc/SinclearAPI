<?php

declare(strict_types=1);

// Matrix Application-Service Transaktions-Endpunkt (Stub).
//
// Continuwuity ruft diesen Endpunkt fuer Transaktionen des Application Service
// auf. In dieser Phase werden KEINE eingehenden Events verarbeitet — der
// Endpunkt validiert ausschliesslich das hs_token und antwortet mit `{}`.
//
// Standalone-Datei (analog dav.php), damit der Endpunkt unabhaengig von
// /api/v2, JWT-Middleware und CORS ist. Es wird kein Code aus src/ ausgeliefert.

require __DIR__ . '/../../vendor/autoload.php';

$rootDir = dirname(__DIR__, 2);
$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$expected = $_ENV['MATRIX_HS_TOKEN'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($expected === '' || !hash_equals('Bearer ' . $expected, $auth)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"errcode":"M_FORBIDDEN","error":"Invalid hs_token"}';
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo '{}';
