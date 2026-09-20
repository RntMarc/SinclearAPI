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
 * Validates the shared secret via the X-Centrifugo-Proxy-Key header
 * (set in Centrifugo config.http.static_headers). HTTPS is enforced
 * separately by RequireHttpsMiddleware.
 */
final readonly class CentrifugoProxyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $proxyKey,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $providedKey = $request->getHeaderLine('X-Centrifugo-Proxy-Key');
        if ($providedKey === '' || !hash_equals($this->proxyKey, $providedKey)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403);
        }

        return $handler->handle($request);
    }
}
