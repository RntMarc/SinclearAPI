<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Service\RateLimitService;

/**
 * Stricter rate limiting for authentication endpoints.
 */
final class LoginThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitService $rateLimitService,
        private readonly Settings $settings
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $max = (int) $this->settings->get('rate_limit.auth_requests', 10);
        $window = (int) $this->settings->get('rate_limit.auth_window', 60);

        if (!$this->rateLimitService->isAllowed('auth:' . $ip, $max, $window)) {
            throw HttpException::tooManyRequests('auth_rate_limit_exceeded');
        }

        return $handler->handle($request);
    }
}
