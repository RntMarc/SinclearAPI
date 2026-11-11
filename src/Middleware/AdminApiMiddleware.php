<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\TokenService;

/**
 * JWT-basierte Autorisierung fuer die Admin-JSON-API (`/admin-api/*`).
 *
 * Im Gegensatz zum session-basierten AdminMiddleware (HTML-Dashboard) wird
 * hier ein Bearer-Token verlangt:
 *  - $requireElevated = false (Lesen): normaler Access-Token mit isAdmin=true.
 *  - $requireElevated = true  (Schreiben): kurzlebiger Step-up-Admin-Token mit
 *    scope='admin' (siehe TokenService::createAdminAccessToken()).
 */
final readonly class AdminApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenService $tokenService,
        private bool $requireElevated = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return ResponseFactory::json(['error' => 'unauthorized'], 401);
        }

        $token = substr($authHeader, 7);
        $payload = $this->tokenService->validateAccessToken($token);

        if ($payload === null) {
            return ResponseFactory::json(['error' => 'token_invalid'], 401);
        }

        if (!($payload->isAdmin ?? false)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403);
        }

        if ($this->requireElevated && ($payload->scope ?? null) !== TokenService::ADMIN_SCOPE) {
            return ResponseFactory::json(['error' => 'admin_step_up_required'], 403);
        }

        $user = new AuthenticatedUser(
            id: $payload->sub,
            email: $payload->email ?? '',
            isAdmin: true,
            jti: $payload->jti ?? '',
        );

        $request = $request->withAttribute(AuthenticatedUser::class, $user);

        return $handler->handle($request);
    }
}
