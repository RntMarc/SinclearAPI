<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class RecipePolicy
{
    public function canModify(AuthenticatedUser $user, string $creatorId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $creatorId;
    }
}
