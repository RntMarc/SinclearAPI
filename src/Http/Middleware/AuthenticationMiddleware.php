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

        if ($authHeader === '') {
            $serverParams = $request->getServerParams();
            error_log("[AUTH MIDDLEWARE] Fallback: checking server params for HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION");
            $authHeader = $serverParams['HTTP_AUTHORIZATION']
                ?? $serverParams['REDIRECT_HTTP_AUTHORIZATION']
                ?? $serverParams['Authorization']
                ?? '';
            if ($authHeader !== '') {
                error_log("[AUTH MIDDLEWARE] Fallback found Authorization in server params");
            }
        }

        error_log("[AUTH MIDDLEWARE] Authorization header present: " . ($authHeader !== '' ? 'yes' : 'no'));
        if ($authHeader !== '') {
            error_log("[AUTH MIDDLEWARE] Authorization header starts with Bearer: " . (str_starts_with($authHeader, 'Bearer ') ? 'yes' : 'no'));
            error_log("[AUTH MIDDLEWARE] Authorization header preview: " . mb_substr($authHeader, 0, 50) . '...');
        } else {
            error_log("[AUTH MIDDLEWARE] All PSR-7 headers: " . json_encode($request->getHeaders()));
            error_log("[AUTH MIDDLEWARE] _SERVER keys: " . json_encode(array_keys($serverParams)));
        }

        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            throw HttpException::unauthorized();
        }

        $token = substr($authHeader, 7);
        error_log("[AUTH MIDDLEWARE] Calling validateAccessToken with token preview: " . mb_substr($token, 0, 30) . '...');
        $user = $this->tokenService->validateAccessToken($token);
        error_log("[AUTH MIDDLEWARE] validateAccessToken succeeded for user: " . $user->id);

        return $handler->handle($request->withAttribute(AuthenticatedUser::class, $user));
    }
}
