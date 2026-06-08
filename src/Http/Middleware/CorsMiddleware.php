<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\Settings;

/**
 * Handles CORS preflight and response headers.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $this->settings->get('cors.allowed_origins', ['*']);

        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response(204);
            return $this->addCorsHeaders($response, $origin, $allowed);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin, $allowed);
    }

    /**
     * @param list<string> $allowed
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin, array $allowed): ResponseInterface
    {
        if ($origin === '') {
            return $response;
        }

        if (in_array('*', $allowed, true) || in_array($origin, $allowed, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', in_array('*', $allowed, true) ? '*' : $origin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
