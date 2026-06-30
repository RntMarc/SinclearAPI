<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class FeedbackPolicy
{
    public function canDelete(AuthenticatedUser $user, string $ownerId, int $upvoteCount): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $ownerId && $upvoteCount < 3;
    }

    public function canUpdateStatus(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canDeleteComment(AuthenticatedUser $user, string $commentUserId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $commentUserId;
    }

    public function canEditComment(AuthenticatedUser $user, string $commentUserId, string $createdAt): bool
    {
        if ($user->id !== $commentUserId) {
            return false;
        }

        $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $created->getTimestamp();

        return $diff <= 600;
    }
}
