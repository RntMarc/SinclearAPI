<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\Auth\TokenService;

/**
 * Optionally validates JWT access tokens and attaches the authenticated user to the request.
 * Does not throw exceptions if the token is missing or invalid.
 */
final class OptionalAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokenService
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
            try {
                $token = substr($authHeader, 7);
                $user = $this->tokenService->validateAccessToken($token);
                $request = $request->withAttribute(AuthenticatedUser::class, $user);
            } catch (\Throwable) {
                // If token is invalid, we just continue as guest
            }
        }

        return $handler->handle($request);
    }
}
