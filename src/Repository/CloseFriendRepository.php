<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

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

    /**
     * Alle Nutzer-IDs, die der anfragende Nutzer als engen Freund markiert hat
     * (ausgehende Richtung: CloseFriend { userId, friendId }).
     *
     * @return list<string>
     */
    public function listFriendIdsFor(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT friendId FROM CloseFriend WHERE userId = ?');
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'friendId');
    }

    /**
     * Markierung setzen. Idempotent über den Unique-Key (userId, friendId).
     */
    public function add(string $userId, string $friendId): void
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO CloseFriend (id, userId, friendId, createdAt) VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $userId, $friendId]);
    }

    /**
     * Markierung entfernen. Idempotent (kein Fehler bei nicht vorhandener Zeile).
     */
    public function remove(string $userId, string $friendId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM CloseFriend WHERE userId = ? AND friendId = ?');
        $stmt->execute([$userId, $friendId]);
    }
}
