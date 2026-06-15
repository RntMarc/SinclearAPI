<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins,
        private ?LoggerInterface $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($this->isOriginAllowed($origin)) {
            if ($request->getMethod() === 'OPTIONS') {
                $response = ResponseFactory::noContent();
            } else {
                $response = $handler->handle($request);
            }

            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept')
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Max-Age', '86400')
                ->withHeader('Vary', 'Origin');
        }

        $this->logger?->warning('Blocked CORS request from origin not in allowed list', [
            'origin' => $origin,
            'allowed_origins' => $this->allowedOrigins,
        ]);

        if ($request->getMethod() === 'OPTIONS') {
            return ResponseFactory::noContent();
        }

        return $handler->handle($request);
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        foreach ($this->allowedOrigins as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            if ($allowed === '*' || $allowed === $origin) {
                return true;
            }
        }

        return false;
    }
}
