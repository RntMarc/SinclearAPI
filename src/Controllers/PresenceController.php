<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Centrifugo\CentrifugoClientInterface;
use Sinclear\Api\Services\UserActivityService;

final readonly class PresenceController
{
    public function __construct(
        private CentrifugoClientInterface $centrifugoClient,
        private UserActivityService $activityService,
    ) {}

    /**
     * Get presence for a single user.
     *
     * Combines Centrifugo real-time presence (user:<userId> channel) with
     * last known activity from the UserActivity table.
     *
     * GET /chat/presence/{userId}
     */
    public function getUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        $userId = $args['userId'] ?? '';
        if ($userId === '') {
            return ResponseFactory::json(['error' => 'invalid_user_id'], 400, $response);
        }

        $presence = $this->centrifugoClient->getUserPresence($userId);

        // Enrich with last known activity if offline
        if (!$presence['online']) {
            $activity = $this->activityService->getLastActive($userId);
            if ($activity !== null) {
                $presence['lastSeen'] = $activity['lastActiveAt'];
            }
        }

        return ResponseFactory::json(['data' => $presence], 200, $response);
    }

    /**
     * Get presence for multiple users (bulk).
     *
     * GET /chat/presence?userIds[]=...&userIds[]=...
     */
    public function getBulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $params = $request->getQueryParams();
        $userIds = $params['userIds'] ?? [];

        if (!is_array($userIds) || $userIds === []) {
            return ResponseFactory::json(['error' => 'userIds_required'], 400, $response);
        }

        // Limit to 50 users per request
        $userIds = array_values(array_unique(array_slice($userIds, 0, 50)));

        $presences = $this->centrifugoClient->getBulkUserPresence($userIds);

        // Enrich offline users with last known activity
        $offlineIds = [];
        foreach ($presences as $userId => $presence) {
            if (!$presence['online']) {
                $offlineIds[] = $userId;
            }
        }

        if ($offlineIds !== []) {
            $activities = $this->activityService->getBulkLastActive($offlineIds);
            foreach ($offlineIds as $userId) {
                if (isset($activities[$userId])) {
                    $presences[$userId]['lastSeen'] = $activities[$userId]['lastActiveAt'];
                }
            }
        }

        return ResponseFactory::json(['data' => $presences], 200, $response);
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
