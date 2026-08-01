<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class ExplorePolicy
{
    private const int DELETE_WINDOW_SECONDS = 1800;

    public function canDelete(AuthenticatedUser $user, string $creatorId, bool $hasReviews, string $createdAt): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        if ($user->id !== $creatorId) {
            return false;
        }

        if ($hasReviews) {
            return false;
        }

        $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $created->getTimestamp();

        return $diff <= self::DELETE_WINDOW_SECONDS;
    }
}
