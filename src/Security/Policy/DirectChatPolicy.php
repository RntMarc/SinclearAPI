<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Policy: direct chats – participants only.
 */
final class DirectChatPolicy extends AbstractPolicy
{
    public function canView(AuthenticatedUser $user, array $resource): bool
    {
        return $user->isAdmin
            || (string) ($resource['userAId'] ?? '') === $user->id
            || (string) ($resource['userBId'] ?? '') === $user->id;
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return [];
    }
}
