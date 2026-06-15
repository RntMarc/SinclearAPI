<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DiscoverGastronomyRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByPlaceId(string $placeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM DiscoverGastronomy WHERE placeId = ?');
        $stmt->execute([$placeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $placeId, string $cuisine): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DiscoverGastronomy (id, placeId, cuisine) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $placeId, $cuisine]);
        return $id;
    }

    public function update(string $placeId, string $cuisine): void
    {
        $existing = $this->findByPlaceId($placeId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare('UPDATE DiscoverGastronomy SET cuisine = ? WHERE placeId = ?');
            $stmt->execute([$cuisine, $placeId]);
        } else {
            $this->create($placeId, $cuisine);
        }
    }

    public function delete(string $placeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM DiscoverGastronomy WHERE placeId = ?');
        $stmt->execute([$placeId]);
    }
}
