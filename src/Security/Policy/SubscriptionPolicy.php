<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class SubscriptionPolicy
{
    public function canView(AuthenticatedUser $user, array $subscription): bool
    {
        return $user->isAdmin || ($subscription['role'] ?? '') === 'creator' || ($subscription['role'] ?? '') === 'participant';
    }

    public function canModify(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }
}
