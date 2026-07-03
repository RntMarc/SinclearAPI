<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class LocationSharingPolicy
{
    public function canView(AuthenticatedUser $user, string $ownerId, bool $isRecipient): bool
    {
        return $user->isAdmin || $user->id === $ownerId || $isRecipient;
    }

    public function canModify(AuthenticatedUser $user, string $ownerId): bool
    {
        return $user->isAdmin || $user->id === $ownerId;
    }
}
