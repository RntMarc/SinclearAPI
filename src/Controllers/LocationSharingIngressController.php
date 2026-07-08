<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\LocationSharingService;

final readonly class LocationSharingIngressController
{
    public function __construct(
        private LocationSharingService $service,
        private LoggerInterface $logger,
    ) {}

    private function logDebug(ServerRequestInterface $request, string $message, array $context = []): void
    {
        $this->logger->debug('[LocationSharingIngress] ' . $message, array_merge([
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
            'queryParams' => $request->getQueryParams(),
            'body' => $request->getBody()->__toString(),
            'parsedBody' => $request->getParsedBody(),
        ], $context));
    }

    private function extractFromQueryOrBody(ServerRequestInterface $request, string $queryKey, string $bodyKey): mixed
    {
        $params = $request->getQueryParams();
        if (isset($params[$queryKey]) && $params[$queryKey] !== '') {
            return $params[$queryKey];
        }
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[$bodyKey])) {
            return $body[$bodyKey];
        }
        return null;
    }

    public function handleOsmAnd(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($params['acc'] ?? null);
        $recordedAt = $this->parseTimestamp($params['timestamp'] ?? null);

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleGpsLogger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($params['acc'] ?? null);
        $recordedAt = $this->parseTimestamp($params['timestamp'] ?? null);

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleOwntracks(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $this->logDebug($request, 'invalid_body');
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        $lat = $this->parseFloat($body['lat'] ?? null);
        $lon = $this->parseFloat($body['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($body['acc'] ?? null);
        $recordedAt = isset($body['ts']) ? $this->parseTimestamp((string) $body['ts']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleUlogger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = null;
        $recordedAt = isset($params['time']) ? $this->parseTimestamp($params['time']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleTraccar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $lat = $this->parseFloat($this->extractFromQueryOrBody($request, 'lat', 'lat'));
        $lon = $this->parseFloat($this->extractFromQueryOrBody($request, 'lon', 'lon'));

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($this->extractFromQueryOrBody($request, 'accuracy', 'accuracy'));
        $recordedAt = $this->parseTimestamp($this->extractFromQueryOrBody($request, 'timestamp', 'timestamp'));

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleOpenGTS(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($params['gpsAccuracy'] ?? null);
        $recordedAt = isset($params['time']) ? $this->parseTimestamp($params['time']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleOverland(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $this->logDebug($request, 'invalid_body');
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        $lat = null;
        $lon = null;

        if (isset($body['geometry']['coordinates']) && is_array($body['geometry']['coordinates'])) {
            $coords = $body['geometry']['coordinates'];
            $lon = $this->parseFloat($coords[0] ?? null);
            $lat = $this->parseFloat($coords[1] ?? null);
        }

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($body['properties']['horizontal_accuracy'] ?? null);
        $recordedAt = isset($body['properties']['timestamp']) ? $this->parseTimestamp($body['properties']['timestamp']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleLocusMap(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($params['acc'] ?? null);
        $recordedAt = isset($params['time']) ? $this->parseTimestamp($params['time']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleGenericGet(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $params = $request->getQueryParams();
        $lat = $this->parseFloat($params['lat'] ?? null);
        $lon = $this->parseFloat($params['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($params['acc'] ?? null);
        $recordedAt = isset($params['timestamp']) ? $this->parseTimestamp($params['timestamp']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function handleGenericPost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->validateToken($args['token'] ?? '');
        if ($token === null) {
            $this->logDebug($request, 'invalid_token');
            return ResponseFactory::json(['error' => 'invalid_token'], 400, $response);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $this->logDebug($request, 'invalid_body');
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        $lat = $this->parseFloat($body['lat'] ?? null);
        $lon = $this->parseFloat($body['lon'] ?? null);

        if ($lat === null || $lon === null) {
            $this->logDebug($request, 'lat_lon_required');
            return ResponseFactory::json(['error' => 'lat_lon_required'], 400, $response);
        }

        $accuracy = $this->parseFloat($body['acc'] ?? null);
        $recordedAt = isset($body['timestamp']) ? $this->parseTimestamp($body['timestamp']) : null;

        try {
            $this->service->addLocationByToken($token, $lat, $lon, $accuracy, $recordedAt);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $this->logDebug($request, $e->getMessage());
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    private function validateToken(?string $token): ?string
    {
        if ($token === null || $token === '' || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
            return null;
        }
        return strtolower($token);
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = filter_var($value, FILTER_VALIDATE_FLOAT);
        return $num !== false ? $num : null;
    }

    private function parseTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = filter_var($value, FILTER_VALIDATE_INT);
        if ($ts !== false) {
            if ($ts > 10000000000) {
                $ts = (int) ($ts / 1000);
            }
            $utc = new \DateTimeZone('UTC');
            $dt = \DateTime::createFromFormat('U', (string) $ts, $utc);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $utc = new \DateTimeZone('UTC');
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value, $utc);
        if ($dt !== false) {
            return $dt->format('Y-m-d H:i:s');
        }

        $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $value, $utc);
        if ($dt !== false) {
            return $dt->format('Y-m-d H:i:s');
        }

        return null;
    }
}
