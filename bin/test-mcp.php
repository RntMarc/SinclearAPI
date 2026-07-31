#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Integration test for the MCP documentation endpoint.
 *
 * Verifies the MCP Streamable HTTP handshake and tool calls against a
 * running server. Run this on (or against) the deployed API:
 *
 *   php bin/test-mcp.php
 *   php bin/test-mcp.php https://api.example.com/api/v2/mcp
 *
 * Exit code 0 = all tests passed, 1 = at least one failure.
 */

use GuzzleHttp\Client;

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$url = $argv[1] ?? null;
if ($url === null && isset($_ENV['APP_URL']) && $_ENV['APP_URL'] !== '') {
    $url = rtrim((string) $_ENV['APP_URL'], '/') . '/api/v2/mcp';
}
if ($url === null) {
    fwrite(STDERR, "Keine URL angegeben: php bin/test-mcp.php [URL]\n");
    exit(1);
}

$client = new Client([
    'base_uri' => $url,
    'timeout' => 30,
    'http_errors' => false,
    'headers' => [
        'Accept' => 'application/json, text/event-stream',
        'Content-Type' => 'application/json',
    ],
]);

$results = [];
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failed;
    $results[] = [$name, $ok, $detail];
    if (!$ok) {
        $failed++;
    }
    printf("%s %s%s\n", $ok ? '[PASS]' : '[FAIL]', $name, $detail !== '' ? " — $detail" : '');
}

function send(string $method, string $path, ?array $body = null, array $headers = []): array
{
    global $client;
    $options = ['headers' => $headers];
    if ($body !== null) {
        $options['json'] = $body;
    }
    $response = $client->request($method, $path, $options);
    return [
        'status' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body' => $response->getBody()->getContents(),
    ];
}

echo "MCP-Test gegen: $url\n\n";

// --- 1. Handshake: initialize ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'sinclear-mcp-test', 'version' => '1.0.0'],
    ],
]);

$ok = $resp['status'] === 200;
$data = json_decode($resp['body'], true);
$ok = $ok && is_array($data) && ($data['result']['serverInfo']['name'] ?? null) !== null;
$ok = $ok && isset($data['result']['protocolVersion']);
$ok = $ok && isset($data['result']['capabilities']['tools']);
test('Handshake initialize', $ok, "HTTP {$resp['status']}" . ($ok ? " / Version {$data['result']['protocolVersion']}" : ' / ' . substr($resp['body'], 0, 200)));

// --- 2. initialized notification ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'method' => 'notifications/initialized',
]);
test('Notification initialized → 202', $resp['status'] === 202 && trim($resp['body']) === '', "HTTP {$resp['status']}");

// --- 3. tools/list ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
]);
$data = json_decode($resp['body'], true);
$toolNames = array_column($data['result']['tools'] ?? [], 'name');
$hasTool = in_array('get_documentation', $toolNames, true);
test('tools/list enthält get_documentation', $resp['status'] === 200 && $hasTool, 'Tools: ' . implode(', ', $toolNames));

// --- 4. tools/call index (Übersicht) ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => ['name' => 'get_documentation', 'arguments' => ['topic' => 'index']],
]);
$data = json_decode($resp['body'], true);
$text = $data['result']['content'][0]['text'] ?? '';
$ok = $resp['status'] === 200 && isset($data['result']) && ($data['result']['isError'] ?? true) === false && str_contains($text, 'get_documentation');
test('tools/call topic=index', $ok, 'Zeichen: ' . strlen($text));

// --- 5. tools/call Markdown-Doc (travel) ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'tools/call',
    'params' => ['name' => 'get_documentation', 'arguments' => ['topic' => 'travel']],
]);
$data = json_decode($resp['body'], true);
$text = $data['result']['content'][0]['text'] ?? '';
$ok = $resp['status'] === 200 && ($data['result']['isError'] ?? true) === false && str_contains($text, 'Travel');
test('tools/call topic=travel', $ok, 'Zeichen: ' . strlen($text));

// --- 6. tools/call openapi als strukturiertes JSON ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 5,
    'method' => 'tools/call',
    'params' => ['name' => 'get_documentation', 'arguments' => ['topic' => 'openapi', 'format' => 'json']],
]);
$data = json_decode($resp['body'], true);
$text = $data['result']['content'][0]['text'] ?? '';
$parsed = json_decode($text, true);
$ok = $resp['status'] === 200
    && ($data['result']['isError'] ?? true) === false
    && is_array($parsed)
    && is_array($parsed['endpoints'] ?? null)
    && count($parsed['endpoints']) > 0
    && isset($parsed['info']['title']);
test('tools/call topic=openapi format=json', $ok, 'Endpunkte: ' . count($parsed['endpoints'] ?? []));

// --- 7. tools/call unbekanntes Thema → isError ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 6,
    'method' => 'tools/call',
    'params' => ['name' => 'get_documentation', 'arguments' => ['topic' => 'gibt-es-nicht']],
]);
$data = json_decode($resp['body'], true);
$ok = $resp['status'] === 200 && ($data['result']['isError'] ?? false) === true && str_contains($data['result']['content'][0]['text'] ?? '', 'Unbekanntes Thema');
test('tools/call unbekanntes Thema → isError', $ok);

// --- 8. Unbekannte Methode → JSON-RPC-Fehler -32601 ---
$resp = send('POST', '', [
    'jsonrpc' => '2.0',
    'id' => 7,
    'method' => 'resources/list',
]);
$data = json_decode($resp['body'], true);
$ok = $resp['status'] === 200 && ($data['error']['code'] ?? null) === -32601;
test('Unbekannte Methode → -32601', $ok);

// --- 9. Ungültiges JSON → HTTP 400 + Parse error ---
$resp = $client->request('POST', '', [
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    'body' => '{kein json',
]);
$data = json_decode($resp->getBody()->getContents(), true);
$ok = $resp->getStatusCode() === 400 && ($data['error']['code'] ?? null) === -32700;
test('Ungültiges JSON → 400/-32700', $ok, "HTTP {$resp->getStatusCode()}");

// --- 10. GET → 405 (erlaubt laut MCP-Spec) ---
$resp = $client->request('GET', '', [
    'headers' => ['Accept' => 'text/event-stream'],
]);
$ok = $resp->getStatusCode() === 405 && $resp->hasHeader('Allow');
test('GET → 405 (kein SSE-Push)', $ok, "HTTP {$resp->getStatusCode()}");

echo "\n==============================\n";
echo $failed === 0 ? 'ALLE TESTS BESTANDEN' : "$failed TEST(S) FEHLGESCHLAGEN";
echo "\n";

exit($failed === 0 ? 0 : 1);
