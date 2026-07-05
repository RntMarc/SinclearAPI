<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class LocationSharingRecipientRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function addRecipients(string $sessionId, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO LocationSharingRecipient (id, sessionId, userId, createdAt) VALUES (?, ?, ?, NOW())'
        );

        foreach ($userIds as $userId) {
            $id = Uuid::uuid7()->toString();
            $stmt->execute([$id, $sessionId, $userId]);
        }
    }

    public function getRecipients(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.userId, r.createdAt, u.displayName, u.image
             FROM LocationSharingRecipient r
             JOIN User u ON u.id = r.userId
             WHERE r.sessionId = ?
             ORDER BY r.createdAt ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecipientUserIds(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT userId FROM LocationSharingRecipient WHERE sessionId = ?');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function isRecipient(string $sessionId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM LocationSharingRecipient WHERE sessionId = ? AND userId = ?'
        );
        $stmt->execute([$sessionId, $userId]);
        return $stmt->fetch() !== false;
    }

    public function removeBySession(string $sessionId): void
    {
        $this->pdo->prepare('DELETE FROM LocationSharingRecipient WHERE sessionId = ?')->execute([$sessionId]);
    }
}
