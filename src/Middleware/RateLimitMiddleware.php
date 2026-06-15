<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\RateLimiter;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private int $maxRequests,
        private int $windowSeconds,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->getClientIp($request);

        if (!$this->rateLimiter->isAllowed($ip, $this->maxRequests, $this->windowSeconds)) {
            return ResponseFactory::json(
                ['error' => 'rate_limit_exceeded'],
                429,
            );
        }

        return $handler->handle($request);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
