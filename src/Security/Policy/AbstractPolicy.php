<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Base policy with owner-based defaults.
 */
abstract class AbstractPolicy implements PolicyInterface
{
    protected function ownerColumn(): string
    {
        return 'userId';
    }

    public function canList(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        $col = $this->ownerColumn();
        if (isset($resource[$col])) {
            return (string) $resource[$col] === $user->id;
        }
        if (isset($resource['creatorId'])) {
            return (string) $resource['creatorId'] === $user->id;
        }
        return true;
    }

    public function canCreate(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function canUpdate(AuthenticatedUser $user, array $resource): bool
    {
        return $this->canView($user, $resource);
    }

    public function canDelete(AuthenticatedUser $user, array $resource): bool
    {
        return $this->canUpdate($user, $resource);
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        if ($user->isAdmin) {
            return [];
        }
        return [$this->ownerColumn() => $user->id];
    }
}
