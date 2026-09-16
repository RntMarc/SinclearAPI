<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;

/**
 * Secures Centrifugo proxy endpoints (/centrifugo/*).
 *
 * Validates:
 * - Shared secret via X-Centrifugo-Proxy-Key header (set in Centrifugo config.http.static_headers)
 * - HTTPS requirement
 */
final readonly class CentrifugoProxyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $proxyKey,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // HTTPS check
        $serverParams = $request->getServerParams();
        $https = $serverParams['HTTPS'] ?? '';
        $forwardedProto = $serverParams['HTTP_X_FORWARDED_PROTO'] ?? '';

        $isSecure = $https === 'on'
            || $https === '1'
            || strtolower($forwardedProto) === 'https';

        if (!$isSecure) {
            return ResponseFactory::json(['error' => 'ssl_required'], 403);
        }

        // Shared secret check
        $providedKey = $request->getHeaderLine('X-Centrifugo-Proxy-Key');
        if ($providedKey === '' || !hash_equals($this->proxyKey, $providedKey)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403);
        }

        return $handler->handle($request);
    }
}
