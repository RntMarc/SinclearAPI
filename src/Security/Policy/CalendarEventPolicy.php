<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class CalendarEventPolicy
{
    public function canModify(AuthenticatedUser $user, string $creatorId): bool
    {
        return $user->isAdmin || $user->id === $creatorId;
    }
}
