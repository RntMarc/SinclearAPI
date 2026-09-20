<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Middleware\CentrifugoProxyMiddleware;

class CentrifugoProxyMiddlewareTest extends TestCase
{
    private const string PROXY_KEY = 'test-proxy-secret-key';
    private CentrifugoProxyMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new CentrifugoProxyMiddleware(proxyKey: self::PROXY_KEY);
    }

    public function testValidKeyPassesThrough(): void
    {
        $request = $this->createRequest(proxyKey: self::PROXY_KEY);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($this->createResponse(200));

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMissingKeyReturns403(): void
    {
        $request = $this->createRequest(proxyKey: '');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('forbidden', $body['error']);
    }

    public function testWrongKeyReturns403(): void
    {
        $request = $this->createRequest(proxyKey: 'wrong-key');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function createRequest(string $proxyKey): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(function (string $name) use ($proxyKey) {
                return $name === 'X-Centrifugo-Proxy-Key' ? $proxyKey : '';
            });

        return $request;
    }

    private function createResponse(int $statusCode): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn(\GuzzleHttp\Psr7\Utils::streamFor('{}'));
        return $response;
    }
}
