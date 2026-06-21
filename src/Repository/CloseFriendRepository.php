<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class CloseFriendRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function isCloseFriend(string $userId, string $friendId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM CloseFriend WHERE userId = ? AND friendId = ?');
        $stmt->execute([$userId, $friendId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
