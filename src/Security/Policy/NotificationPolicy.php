<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class NotificationPolicy
{
    public function canAccess(AuthenticatedUser $user, array $notification): bool
    {
        return $user->id === $notification['userId'];
    }

    public function canManageDevice(AuthenticatedUser $user, array $device): bool
    {
        return $user->id === $device['userId'];
    }
}
