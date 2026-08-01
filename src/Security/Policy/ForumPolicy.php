<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class ForumPolicy
{
    private const int DELETE_WINDOW_SECONDS = 1800;

    public function canCreateForum(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canModifyForum(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canDeleteForum(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }

    public function canDeletePost(AuthenticatedUser $user, string $ownerId, bool $hasComments, string $createdAt): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        if ($user->id !== $ownerId) {
            return false;
        }

        $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $created->getTimestamp();

        if ($diff <= self::DELETE_WINDOW_SECONDS) {
            return true;
        }

        return false;
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
