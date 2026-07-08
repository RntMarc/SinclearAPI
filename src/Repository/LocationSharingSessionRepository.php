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
        $token = bin2hex(random_bytes(16));

        $hasDuration = isset($data['durationSeconds']) && $data['durationSeconds'] !== null;
        $expiresAtSql = $hasDuration
            ? 'DATE_ADD(NOW(), INTERVAL ? SECOND)'
            : 'NULL';

        $stmt = $this->pdo->prepare(
            "INSERT INTO LocationSharingSession
                (id, token, ownerId, sharingMode, durationSeconds, frequencySeconds, isActive, startedAt, expiresAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), $expiresAtSql, NOW(), NOW())"
        );

        $params = [
            $id,
            $token,
            $data['ownerId'],
            $data['sharingMode'] ?? 'location',
            $data['durationSeconds'] ?? null,
            $data['frequencySeconds'],
        ];
        if ($hasDuration) {
            $params[] = $data['durationSeconds'];
        }
        $stmt->execute($params);
        return $id;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM LocationSharingSession WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM LocationSharingSession WHERE token = ?');
        $stmt->execute([$token]);
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

        $sets[] = 'updatedAt = NOW()';
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

    public function countLocations(string $sessionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM LocationSharingLocation WHERE sessionId = ?');
        $stmt->execute([$sessionId]);
        return (int) $stmt->fetchColumn();
    }

    public function listByOwner(string $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM LocationSharingSession
              WHERE ownerId = ? AND isActive = 1 AND (expiresAt IS NULL OR expiresAt > NOW())
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
              WHERE r.userId = ? AND s.isActive = 1 AND (s.expiresAt IS NULL OR s.expiresAt > NOW())
             ORDER BY s.createdAt DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
