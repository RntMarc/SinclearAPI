<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class LocationSharingSessionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO LocationSharingSession
                (id, ownerId, durationSeconds, frequencySeconds, isActive, startedAt, expiresAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, 1, NOW(3), DATE_ADD(NOW(3), INTERVAL ? SECOND), NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['ownerId'],
            $data['durationSeconds'],
            $data['frequencySeconds'],
            $data['durationSeconds'],
        ]);
        return $id;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM LocationSharingSession WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (array_key_exists('isActive', $data)) {
            $sets[] = 'isActive = ?';
            $values[] = $data['isActive'] ? 1 : 0;
        }
        if (array_key_exists('durationSeconds', $data)) {
            $sets[] = 'durationSeconds = ?';
            $values[] = $data['durationSeconds'];
            $sets[] = 'expiresAt = DATE_ADD(startedAt, INTERVAL ? SECOND)';
            $values[] = $data['durationSeconds'];
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $id;

        $sql = 'UPDATE LocationSharingSession SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($values);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM LocationSharingLocation WHERE sessionId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM LocationSharingRecipient WHERE sessionId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM LocationSharingSession WHERE id = ?')->execute([$id]);
    }

    public function listByOwner(string $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM LocationSharingSession
             WHERE ownerId = ? AND isActive = 1 AND expiresAt > NOW(3)
             ORDER BY createdAt DESC'
        );
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listActiveAsRecipient(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.id AS ownerId, u.displayName, u.image
             FROM LocationSharingSession s
             JOIN LocationSharingRecipient r ON r.sessionId = s.id
             JOIN User u ON u.id = s.ownerId
             WHERE r.userId = ? AND s.isActive = 1 AND s.expiresAt > NOW(3)
             ORDER BY s.createdAt DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
