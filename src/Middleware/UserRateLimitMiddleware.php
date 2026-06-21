<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\RateLimiter;

final readonly class UserRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private int $maxRequests,
        private int $windowSeconds,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);

        if (!$user instanceof AuthenticatedUser) {
            return $handler->handle($request);
        }

        $key = 'proxy:' . $user->id;

        if (!$this->rateLimiter->isAllowed($key, $this->maxRequests, $this->windowSeconds)) {
            return ResponseFactory::json(
                ['error' => 'rate_limit_exceeded'],
                429,
            );
        }

        return $handler->handle($request);
    }
}
