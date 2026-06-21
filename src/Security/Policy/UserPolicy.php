<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class UserPolicy
{
    public function __construct(
        private CloseFriendRepository $closeFriendRepo,
    ) {}

    public function canView(AuthenticatedUser $requester, string $targetUserId, int $visibilityLevel): bool
    {
        if ($requester->id === $targetUserId) {
            return true;
        }

        if ($visibilityLevel === 0) {
            return false;
        }

        if ($visibilityLevel === 1) {
            return true;
        }

        if ($visibilityLevel === 2) {
            return $this->closeFriendRepo->isCloseFriend($targetUserId, $requester->id);
        }

        return false;
    }
}
