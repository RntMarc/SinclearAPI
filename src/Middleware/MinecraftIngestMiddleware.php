<?php

declare(strict_types=1);

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\RateLimiter;

/**
 * Sichert den Minecraft-Ingest-Endpoint (POST /minecraft/stats).
 *
 * Wird als globaler, pfadgebundener Guard in public/index.php direkt nach dem
 * BodyParsingMiddleware registriert, damit der Key geprueft wird, BEVOR der
 * Body gelesen und geparst wird. Die Reihenfolge der Pruefungen:
 *
 *   1. Key vorhanden     -> 401 unauthorized (Fehlversuch je IP gezaehlt)
 *   2. Key konfiguriert  -> 503 ingest_disabled
 *   3. Key gueltig       -> 401 invalid_key (Fehlversuche je IP begrenzt)
 *   4. Rate-Limit je Key -> 429 rate_limited + Retry-After
 *   5. Body-Groesse      -> 413 payload_too_large
 *
 * HTTPS wird durch die globale RequireHttpsMiddleware erzwungen (liegt im
 * Stack vor diesem Guard). Der Key wird nie geloggt.
 */
final readonly class MinecraftIngestMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'minecraft_ingest_authenticated';

    private const string PATH = '/api/v2/minecraft/stats';

    public function __construct(
        private string $ingestKey,
        private int $maxBodyBytes,
        private int $rateLimitPerMinute,
        private int $invalidKeyRateLimitPerMinute,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !$this->isIngestPath($request)) {
            return $handler->handle($request);
        }

        $providedKey = $this->extractKey($request);

        if ($providedKey === null || $providedKey === '') {
            return $this->rejectInvalidKey($request, 'unauthorized');
        }

        if ($this->ingestKey === '') {
            $this->logger->warning('[MinecraftIngest] key not configured (ingest disabled)');
            return ResponseFactory::json(['error' => 'ingest_disabled'], 503);
        }

        if (!hash_equals($this->ingestKey, $providedKey)) {
            return $this->rejectInvalidKey($request, 'invalid_key');
        }

        if (!$this->rateLimiter->isAllowed('minecraft_key', $this->rateLimitPerMinute, 60)) {
            $this->logger->warning('[MinecraftIngest] rate limited');
            return $this->rateLimited();
        }

        if ($this->exceedsBodyLimit($request)) {
            $this->logger->warning('[MinecraftIngest] payload too large');
            return ResponseFactory::json(['error' => 'payload_too_large'], 413);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, true));
    }

    private function rejectInvalidKey(ServerRequestInterface $request, string $error): ResponseInterface
    {
        $ip = $this->getClientIp($request);
        $this->logger->warning('[MinecraftIngest] invalid key attempt', ['ip' => $ip]);

        if (!$this->rateLimiter->isAllowed('minecraft_ip:' . $ip, $this->invalidKeyRateLimitPerMinute, 60)) {
            return $this->rateLimited();
        }

        return ResponseFactory::json(['error' => $error], 401);
    }

    private function rateLimited(): ResponseInterface
    {
        return ResponseFactory::json(['error' => 'rate_limited'], 429)
            ->withHeader('Retry-After', '60');
    }

    private function isIngestPath(ServerRequestInterface $request): bool
    {
        return rtrim($request->getUri()->getPath(), '/') === self::PATH;
    }

    private function extractKey(ServerRequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth === '') {
            return null;
        }

        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function exceedsBodyLimit(ServerRequestInterface $request): bool
    {
        $length = trim($request->getHeaderLine('Content-Length'));
        if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxBodyBytes) {
            return true;
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Nur bis max+1 Bytes lesen, damit uebergrosse Bodies nicht vollstaendig
        // in den Speicher gezogen werden.
        $read = '';
        while (strlen($read) <= $this->maxBodyBytes && !$body->eof()) {
            $chunk = $body->read($this->maxBodyBytes + 1 - strlen($read));
            if ($chunk === '') {
                break;
            }
            $read .= $chunk;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return strlen($read) > $this->maxBodyBytes;
    }
}
