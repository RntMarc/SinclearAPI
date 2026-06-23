<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class ReviewPolicy
{
    public function canModify(AuthenticatedUser $user, string $reviewUserId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $reviewUserId;
    }
}
