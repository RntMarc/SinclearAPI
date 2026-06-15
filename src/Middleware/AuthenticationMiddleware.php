<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\TokenService;

final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenService $tokenService,
        private bool $required = true,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            if ($this->required) {
                return ResponseFactory::json(
                    ['error' => 'unauthorized'],
                    401,
                );
            }
            return $handler->handle($request);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            if ($this->required) {
                return ResponseFactory::json(
                    ['error' => 'unauthorized'],
                    401,
                );
            }
            return $handler->handle($request);
        }

        $token = substr($authHeader, 7);
        $payload = $this->tokenService->validateAccessToken($token);

        if ($payload === null) {
            if ($this->required) {
                return ResponseFactory::json(
                    ['error' => 'token_invalid'],
                    401,
                );
            }
            return $handler->handle($request);
        }

        $user = new AuthenticatedUser(
            id: $payload->sub,
            email: $payload->email,
            isAdmin: $payload->isAdmin ?? false,
            jti: $payload->jti,
        );

        $request = $request->withAttribute(AuthenticatedUser::class, $user);

        return $handler->handle($request);
    }
}
