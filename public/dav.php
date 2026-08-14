<?php

declare(strict_types=1);

use Sinclear\Api\Dav\DavServerFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = require __DIR__ . '/../config/container.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');

// RFC 6764 Discovery: CalDAV/CardDAV-Clients finden den Server ueber
// /.well-known/caldav und /.well-known/carddav (Redirect auf die Basis-URL).
if ($path === '/.well-known/caldav' || $path === '/.well-known/carddav') {
    http_response_code(301);
    header('Location: /dav/');
    echo 'Moved Permanently';
    exit;
}

// HTTPS-Zwang (analog zu RequireHttpsMiddleware), ausser in der Testumgebung.
$appEnv = $_ENV['APP_ENV'] ?? 'production';
$https = $_SERVER['HTTPS'] ?? '';
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$isSecure = $https === 'on'
    || $https === '1'
    || strtolower($forwardedProto) === 'https';

if (!$isSecure && $appEnv !== 'test') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ssl_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $server = $container->get(DavServerFactory::class)->createServer();
    $server->exec();
} catch (Throwable $e) {
    $logger = $container->get(Psr\Log\LoggerInterface::class);
    $logger->error('DAV: unhandled exception', ['exception' => $e]);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Internal Server Error';
    exit;
}
