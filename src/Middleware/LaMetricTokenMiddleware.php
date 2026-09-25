<?php

declare(strict_types=1);

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Services\LaMetricTokenService;

/**
 * Authentifiziert LaMetric-Poll-Anfragen über einen Token als Query-Parameter
 * (oder Header). Wirft bewusst keinen Fehler: Der Endpunkt antwortet immer mit
 * HTTP 200 und einem aussagekräftigen Fehler-Frame. Daher werden die User-ID
 * bzw. der Fehlercode als Request-Attribute hinterlegt.
 */
final readonly class LaMetricTokenMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'lametric_user_id';
    public const string ERROR_ATTRIBUTE = 'lametric_token_error';

    public function __construct(
        private LaMetricTokenService $tokenService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null || $token === '') {
            return $handler->handle($request->withAttribute(self::ERROR_ATTRIBUTE, 'missing'));
        }

        $userId = $this->tokenService->validateToken($token);

        if ($userId === null) {
            return $handler->handle($request->withAttribute(self::ERROR_ATTRIBUTE, 'invalid'));
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $userId));
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        if (isset($params['token']) && is_string($params['token']) && $params['token'] !== '') {
            return $params['token'];
        }

        $header = $request->getHeaderLine('X-LaMetric-Token');
        if ($header !== '') {
            return $header;
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
