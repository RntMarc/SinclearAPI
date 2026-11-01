<?php

declare(strict_types=1);

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\ArcadeSessionService;

/**
 * Loest das spiel-interne Arcade-Session-Token zu einer Session auf.
 *
 * Das Token wird bevorzugt als `Authorization: Bearer <token>` gesendet,
 * alternativ als Body-Feld `token` (fuer einfache fetch-Aufrufe aus dem Spiel).
 * Bei Erfolg liegt der Session-Datensatz unter {@see self::ATTRIBUTE}.
 */
final readonly class ArcadeSessionMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'arcade_session';

    public function __construct(
        private ArcadeSessionService $sessionService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null || $token === '') {
            return ResponseFactory::json(['error' => 'unauthorized'], 401);
        }

        $session = $this->sessionService->validate($token);
        if ($session === null) {
            return ResponseFactory::json(['error' => 'invalid_session'], 401);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['token']) && is_string($body['token'])) {
            return $body['token'];
        }

        return null;
    }
}
