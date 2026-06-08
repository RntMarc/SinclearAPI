<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Policy: events with public visibility.
 */
final class EventPolicy extends AbstractPolicy
{
    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        if ((string) ($resource['creatorId'] ?? '') === $user->id) {
            return true;
        }
        return (bool) ($resource['isPublic'] ?? false);
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return [];
    }
}
