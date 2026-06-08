<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Policy: users – self only unless admin.
 */
final class UserListPolicy extends AbstractPolicy
{
    public function canList(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        return $user->isAdmin || (string) $resource['id'] === $user->id;
    }

    public function canCreate(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canUpdate(AuthenticatedUser $user, array $resource): bool
    {
        return $user->isAdmin || (string) $resource['id'] === $user->id;
    }

    public function canDelete(AuthenticatedUser $user, array $resource): bool
    {
        return $user->isAdmin;
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return $user->isAdmin ? [] : ['id' => $user->id];
    }
}
