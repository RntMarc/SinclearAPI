<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\Auth\TokenService;

/**
 * Validates JWT access tokens and attaches the authenticated user to the request.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokenService
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            throw HttpException::unauthorized();
        }

        $token = substr($authHeader, 7);
        $user = $this->tokenService->validateAccessToken($token);

        return $handler->handle($request->withAttribute(AuthenticatedUser::class, $user));
    }
}
