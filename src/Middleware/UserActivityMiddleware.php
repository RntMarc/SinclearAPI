<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Repository\UserActivityRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Middleware that tracks user activity for presence and "last seen" features.
 *
 * Updates the UserActivity table for relevant authenticated requests,
 * excluding background/infra endpoints (token refresh, notification polling, etc.).
 *
 * Rate limited: updates at most once per 30 seconds per user (in-memory cache).
 */
final class UserActivityMiddleware implements MiddlewareInterface
{
    /** @var array<string, int> userId → last update timestamp */
    private array $lastUpdates = [];

    private const int UPDATE_INTERVAL = 30; // seconds

    private const array EXCLUDED_PATHS = [
        '/auth/refresh',
        '/auth/token',
        '/chat/centrifugo/token',
        '/notifications/poll',
        '/notifications/unread-count',
        '/centrifugo/subscribe',
        '/centrifugo/publish',
        '/users/activity',
        '/chat/presence',
        '/webhooks/',
    ];

    public function __construct(
        private UserActivityRepository $activityRepo,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if ($user instanceof AuthenticatedUser) {
            $this->updateIfRelevant($user, $request);
        }

        return $handler->handle($request);
    }

    private function updateIfRelevant(AuthenticatedUser $user, ServerRequestInterface $request): void
    {
        $path = $request->getUri()->getPath();

        // Skip excluded paths
        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return;
            }
        }

        // Rate limit: skip if updated within the last UPDATE_INTERVAL seconds
        $now = time();
        $lastUpdate = $this->lastUpdates[$user->id] ?? 0;
        if (($now - $lastUpdate) < self::UPDATE_INTERVAL) {
            return;
        }

        $this->lastUpdates[$user->id] = $now;

        try {
            $this->activityRepo->updateActivity($user->id, $path);
        } catch (\Throwable) {
            // Silent: activity tracking is best-effort, never break the request
        }
    }
}
