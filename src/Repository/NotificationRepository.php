<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class NotificationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(string $userId, string $type, string $entityId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Notification (id, userId, type, entityId, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $userId, $type, $entityId]);
        return $id;
    }

    public function findById(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, type, entityId, createdAt FROM Notification WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByUser(string $userId, ?string $since, int $limit): array
    {
        if ($since !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, userId, type, entityId, createdAt
                 FROM Notification
                 WHERE userId = ? AND createdAt > ?
                 ORDER BY createdAt ASC
                 LIMIT ?'
            );
            $stmt->execute([$userId, $since, $limit]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, userId, type, entityId, createdAt
                 FROM Notification
                 WHERE userId = ?
                 ORDER BY createdAt ASC
                 LIMIT ?'
            );
            $stmt->execute([$userId, $limit]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM Notification WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteAllByUser(string $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM Notification WHERE userId = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function countByUser(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Notification WHERE userId = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
