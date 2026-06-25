<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class AdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['admin_id'], $_SESSION['admin_email'])) {
            $user = new AuthenticatedUser(
                id: $_SESSION['admin_id'],
                email: $_SESSION['admin_email'],
                isAdmin: true,
                jti: '',
            );

            $request = $request->withAttribute(AuthenticatedUser::class, $user);
            return $handler->handle($request);
        }

        return $this->unauthorizedResponse($request);
    }

    private function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->isBrowserRequest($request)
            ? ResponseFactory::redirect('/api/v2/admin/login', 302)
            : ResponseFactory::json(['error' => 'unauthorized'], 401);
    }

    private function isBrowserRequest(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        return str_contains($accept, 'text/html');
    }
}
