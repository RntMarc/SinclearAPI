<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\UserActivityRepository;

final readonly class UserActivityService
{
    public function __construct(
        private UserActivityRepository $activityRepo,
    ) {}

    /**
     * Get last known activity for a single user.
     *
     * @return array{lastActiveAt: string, lastActiveEndpoint: ?string}|null
     */
    public function getLastActive(string $userId): ?array
    {
        return $this->activityRepo->getLastActive($userId);
    }

    /**
     * Get last known activity for multiple users in bulk.
     *
     * @param list<string> $userIds
     * @return array<string, array{lastActiveAt: string, lastActiveEndpoint: ?string}>
     */
    public function getBulkLastActive(array $userIds): array
    {
        return $this->activityRepo->getBulkLastActive($userIds);
    }
}
