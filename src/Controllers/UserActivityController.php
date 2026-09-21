<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\UserActivityService;

final readonly class UserActivityController
{
    public function __construct(
        private UserActivityService $activityService,
    ) {}

    /**
     * Get last active timestamps for multiple users.
     *
     * GET /users/activity?ids[]=...&ids[]=...
     *
     * Returns the last known activity (endpoint + timestamp) for each requested user.
     * This is a fallback for Centrifugo presence - use /chat/presence for real-time status.
     */
    public function getBulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $params = $request->getQueryParams();
        $userIds = $params['ids'] ?? [];

        if (!is_array($userIds) || $userIds === []) {
            return ResponseFactory::json(['error' => 'ids_required'], 400, $response);
        }

        // Limit to 50 users per request
        $userIds = array_values(array_unique(array_slice($userIds, 0, 50)));

        $activities = $this->activityService->getBulkLastActive($userIds);

        return ResponseFactory::json(['data' => $activities], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
