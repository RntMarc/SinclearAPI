<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Middleware\SecurityHeadersMiddleware;
use Slim\Psr7\Response;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testProcessAddsSecurityHeadersIncludingHsts(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);

        $initialResponse = new Response();
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($initialResponse);

        $response = $middleware->process($request, $handler);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('geolocation=(), microphone=(), camera=()', $response->getHeaderLine('Permissions-Policy'));
        $this->assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }
}
