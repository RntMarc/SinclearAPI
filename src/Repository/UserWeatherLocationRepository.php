<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class UserWeatherLocationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, name, slug, lat, lon, source, sortOrder, createdAt, updatedAt
             FROM UserWeatherLocation
             WHERE userId = ?
             ORDER BY sortOrder ASC, createdAt ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, name, slug, lat, lon, source, sortOrder, createdAt, updatedAt
             FROM UserWeatherLocation WHERE id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    public function countByUserId(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM UserWeatherLocation WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function create(
        string $userId,
        string $name,
        ?string $slug,
        float $lat,
        float $lon,
        string $source,
        int $sortOrder,
    ): string {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO UserWeatherLocation (id, userId, name, slug, lat, lon, source, sortOrder, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([$id, $userId, $name, $slug, $lat, $lon, $source, $sortOrder]);
        return $id;
    }

    public function update(
        string $id,
        string $userId,
        string $name,
        ?string $slug,
        float $lat,
        float $lon,
        string $source,
        int $sortOrder,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE UserWeatherLocation
             SET name = ?, slug = ?, lat = ?, lon = ?, source = ?, sortOrder = ?, updatedAt = NOW(3)
             WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$name, $slug, $lat, $lon, $source, $sortOrder, $id, $userId]);
    }

    public function delete(string $id, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM UserWeatherLocation WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
    }

    public function deleteAllByUserId(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM UserWeatherLocation WHERE userId = ?'
        );
        $stmt->execute([$userId]);
    }

    public function deleteAllAndReplace(string $userId, array $locations): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->deleteAllByUserId($userId);

            $sortOrder = 0;
            foreach ($locations as $loc) {
                $this->create(
                    userId: $userId,
                    name: $loc['name'],
                    slug: $loc['slug'] ?? null,
                    lat: $loc['lat'],
                    lon: $loc['lon'],
                    source: $loc['source'] ?? 'nominatim',
                    sortOrder: $sortOrder++,
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
