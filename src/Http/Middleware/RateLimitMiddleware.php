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
 * Global API rate limiting per IP address.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitService $rateLimitService,
        private readonly Settings $settings
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $max = (int) $this->settings->get('rate_limit.requests', 100);
        $window = (int) $this->settings->get('rate_limit.window', 60);

        if (!$this->rateLimitService->isAllowed('global:' . $ip, $max, $window)) {
            throw HttpException::tooManyRequests();
        }

        return $handler->handle($request);
    }
}
