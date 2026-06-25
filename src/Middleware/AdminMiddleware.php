<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\TokenService;

final readonly class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenService $tokenService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse($request);
        }

        $token = substr($authHeader, 7);
        $payload = $this->tokenService->validateAccessToken($token);

        if ($payload === null) {
            return $this->unauthorizedResponse($request);
        }

        if (!($payload->isAdmin ?? false)) {
            return $this->forbiddenResponse($request);
        }

        $user = new AuthenticatedUser(
            id: $payload->sub,
            email: $payload->email,
            isAdmin: true,
            jti: $payload->jti,
        );

        $request = $request->withAttribute(AuthenticatedUser::class, $user);

        return $handler->handle($request);
    }

    private function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->isBrowserRequest($request)
            ? ResponseFactory::redirect('/api/v2/admin/login', 302)
            : ResponseFactory::json(['error' => 'unauthorized'], 401);
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->isBrowserRequest($request)
            ? ResponseFactory::redirect('/api/v2/admin/login', 302)
            : ResponseFactory::json(['error' => 'forbidden'], 403);
    }

    private function isBrowserRequest(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        return str_contains($accept, 'text/html');
    }
}
