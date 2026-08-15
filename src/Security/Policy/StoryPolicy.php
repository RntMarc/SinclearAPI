<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class StoryPolicy
{
    public function canCreate(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canView(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canMarkViewed(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canDelete(AuthenticatedUser $user, string $creatorId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $creatorId;
    }
}
