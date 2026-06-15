<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class ExplorePolicy
{
    public function canDelete(AuthenticatedUser $user, string $creatorId, bool $hasReviews): bool
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

        return true;
    }
}
