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
            || (string) ($resource['user_a_id'] ?? '') === $user->id
            || (string) ($resource['user_b_id'] ?? '') === $user->id;
    }

    public function listFilters(AuthenticatedUser $user): array
    {
        return [];
    }
}
