<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Policy: chat messages – authenticated read.
 */
final class ChatMessagePolicy extends AbstractPolicy
{
    protected function ownerColumn(): string
    {
        return 'user_id';
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
