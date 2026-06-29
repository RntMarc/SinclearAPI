<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class FeedbackPolicy
{
    public function canDelete(AuthenticatedUser $user, string $ownerId, int $upvoteCount): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $ownerId && $upvoteCount < 3;
    }

    public function canUpdateStatus(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }
}
