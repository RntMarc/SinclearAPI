<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class LocationSharingLocationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO LocationSharingLocation (id, sessionId, latitude, longitude, accuracy, recordedAt, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $id,
            $data['sessionId'],
            $data['latitude'],
            $data['longitude'],
            $data['accuracy'] ?? null,
            $data['recordedAt'],
        ]);
        return $id;
    }

    public function listBySession(string $sessionId, ?string $since): array
    {
        if ($since !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM LocationSharingLocation
                 WHERE sessionId = ? AND recordedAt > ?
                 ORDER BY recordedAt ASC'
            );
            $stmt->execute([$sessionId, $since]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM LocationSharingLocation
                 WHERE sessionId = ?
                 ORDER BY recordedAt ASC'
            );
            $stmt->execute([$sessionId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLastLocation(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM LocationSharingLocation
             WHERE sessionId = ?
             ORDER BY recordedAt DESC
             LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function deleteBySession(string $sessionId): void
    {
        $this->pdo->prepare('DELETE FROM LocationSharingLocation WHERE sessionId = ?')->execute([$sessionId]);
    }
}
