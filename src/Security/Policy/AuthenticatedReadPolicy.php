<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * All authenticated users can read; mutations require ownership or admin.
 */
final class AuthenticatedReadPolicy extends AbstractPolicy
{
    public function canList(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        return true;
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return [];
    }
}
