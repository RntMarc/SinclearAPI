<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Middleware\AdminMiddleware;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Slim\Psr7\Response;

/**
 * @runTestsInSeparateProcesses
 */
final class AdminMiddlewareTest extends TestCase
{
    public function testUnauthenticatedApiRequestReturns401Json(): void
    {
        $middleware = new AdminMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn('application/json');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('unauthorized', $body['error'] ?? null);
    }

    public function testUnauthenticatedBrowserRequestRedirectsToLogin(): void
    {
        $middleware = new AdminMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn('text/html,application/xhtml+xml');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/api/v2/admin/login', $response->getHeaderLine('Location'));
    }

    public function testAuthenticatedSessionInjectsUserAttributeAndPassesHandler(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_id'] = 'admin-uuid-123';
        $_SESSION['admin_email'] = 'admin@example.com';

        $middleware = new AdminMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('withAttribute')
            ->with(
                AuthenticatedUser::class,
                $this->callback(function (AuthenticatedUser $user) {
                    return $user->id === 'admin-uuid-123'
                        && $user->email === 'admin@example.com'
                        && $user->isAdmin === true;
                })
            )
            ->willReturnSelf();

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);

        unset($_SESSION['admin_id'], $_SESSION['admin_email']);
    }
}
