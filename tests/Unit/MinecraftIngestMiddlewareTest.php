<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Sinclear\Api\Middleware\MinecraftIngestMiddleware;
use Sinclear\Api\Services\RateLimiter;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class MinecraftIngestMiddlewareTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/minecraft_test_' . bin2hex(random_bytes(8));
    }

    private function middleware(
        string $ingestKey = self::KEY,
        int $rateLimitPerMinute = 30,
        int $invalidKeyRateLimitPerMinute = 10,
        int $maxBodyBytes = 1000,
    ): MinecraftIngestMiddleware {
        return new MinecraftIngestMiddleware(
            ingestKey: $ingestKey,
            maxBodyBytes: $maxBodyBytes,
            rateLimitPerMinute: $rateLimitPerMinute,
            invalidKeyRateLimitPerMinute: $invalidKeyRateLimitPerMinute,
            rateLimiter: new RateLimiter($this->storageDir),
            logger: new NullLogger(),
        );
    }

    private function request(string $method, string $path, string $auth = '', string $body = ''): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '1.2.3.4']);

        if ($auth !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $auth);
        }
        if ($body !== '') {
            $request = $request->withHeader('Content-Length', (string) strlen($body));
            $request = $request->withBody(Utils::streamFor($body));
        }

        return $request;
    }

    public function testNonIngestPathPassesThrough(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $called = 0;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called++;
                return new Response(200);
            }
        };

        $response = $this->middleware()->process($this->request('POST', '/api/v2/other'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->called);
    }

    public function testNonPostMethodPassesThrough(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $called = 0;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called++;
                return new Response(200);
            }
        };

        $response = $this->middleware()->process($this->request('GET', '/api/v2/minecraft/stats'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->called);
    }

    public function testMissingKeyReturnsUnauthorized(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->request('POST', '/api/v2/minecraft/stats'), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testDisabledIngestReturns503(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->middleware(ingestKey: '')
            ->process($this->request('POST', '/api/v2/minecraft/stats', self::KEY), $handler);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('ingest_disabled', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testWrongKeyReturnsInvalidKey(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->middleware()
            ->process($this->request('POST', '/api/v2/minecraft/stats', 'wrong-key'), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_key', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testValidKeyPassesThroughAndSetsAttribute(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $handled = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = $request;
                return new Response(200);
            }
        };

        $response = $this->middleware()
            ->process($this->request('POST', '/api/v2/minecraft/stats', self::KEY, '{}'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->handled);
        self::assertTrue($handler->handled->getAttribute(MinecraftIngestMiddleware::ATTRIBUTE));
    }

    public function testOversizedBodyReturns413(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->middleware(maxBodyBytes: 10)
            ->process($this->request('POST', '/api/v2/minecraft/stats', self::KEY, str_repeat('a', 11)), $handler);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('payload_too_large', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testRateLimitReturns429(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $called = 0;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called++;
                return new Response(200);
            }
        };

        $middleware = $this->middleware(rateLimitPerMinute: 1);
        $request = $this->request('POST', '/api/v2/minecraft/stats', self::KEY, '{}');

        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());

        $response = $middleware->process($request, $handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
        self::assertSame(1, $handler->called);
    }

    public function testInvalidKeyRateLimitReturns429(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);

        $middleware = $this->middleware(invalidKeyRateLimitPerMinute: 1);
        $request = $this->request('POST', '/api/v2/minecraft/stats', 'wrong-key');

        self::assertSame(401, $middleware->process($request, $handler)->getStatusCode());

        $response = $middleware->process($request, $handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('rate_limited', json_decode((string) $response->getBody(), true)['error']);
    }
}
