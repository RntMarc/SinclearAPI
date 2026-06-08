<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Policy: creator-based with public read access.
 */
final class CreatorPolicy extends AbstractPolicy
{
    protected function ownerColumn(): string
    {
        return 'creatorId';
    }

    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        return true;
    }

    public function canList(AuthenticatedUser $user): bool
    {
        return true;
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return [];
    }
}
