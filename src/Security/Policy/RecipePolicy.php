<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class RecipePolicy
{
    private const int DELETE_WINDOW_SECONDS = 1800;

    public function canModify(AuthenticatedUser $user, string $creatorId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $creatorId;
    }

    public function canDelete(AuthenticatedUser $user, string $creatorId, string $createdAt, bool $isDraft): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        if ($user->id !== $creatorId) {
            return false;
        }

        if ($isDraft) {
            return true;
        }

        $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $created->getTimestamp();

        return $diff <= self::DELETE_WINDOW_SECONDS;
    }

    public function canPublish(AuthenticatedUser $user, string $creatorId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $creatorId;
    }
}
