<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for CloseFriend table.
 */
final class CloseFriendRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'CloseFriend';
    }

    protected function columns(): array
    {
        return ['id', 'userId', 'friendId', 'createdAt'];
    }

    public function isCloseFriend(string $userId, string $friendId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `CloseFriend` WHERE `userId` = :userId AND `friendId` = :friendId LIMIT 1'
        );
        $stmt->execute(['userId' => $userId, 'friendId' => $friendId]);
        return $stmt->fetch() !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findIncoming(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `CloseFriend` WHERE `friendId` = :userId'
        );
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUserAndFriend(string $userId, string $friendId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `CloseFriend` WHERE `userId` = :userId AND `friendId` = :friendId LIMIT 1'
        );
        $stmt->execute(['userId' => $userId, 'friendId' => $friendId]);
        $row = $stmt->fetch();
        return $row === false ? [] : [$row];
    }
}
