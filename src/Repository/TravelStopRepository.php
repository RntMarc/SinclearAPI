<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelStopRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelStop WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function searchByName(string $query, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelStop
             WHERE name LIKE ?
             ORDER BY weight DESC, name ASC
             LIMIT ?'
        );
        $stmt->execute([$query . '%', $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function searchByNameFuzzy(string $query, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *,
                    CASE
                        WHEN name LIKE ? THEN 3
                        WHEN name LIKE ? THEN 2
                        WHEN name LIKE ? THEN 1
                        ELSE 0
                    END AS relevance
             FROM TravelStop
             WHERE name LIKE ? OR name LIKE ? OR name LIKE ?
             ORDER BY relevance DESC, weight DESC, name ASC
             LIMIT ?'
        );
        $prefix = $query;
        $stmt->execute([
            $query,
            '%' . $query,
            '%' . $query . '%',
            $query . '%',
            '%' . $query . '%',
            '%' . $query,
            $limit,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM TravelStop WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelStop (id, name, ril100, latitude, longitude, weight, products, lastUpdated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                ril100 = VALUES(ril100),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                weight = VALUES(weight),
                products = VALUES(products),
                lastUpdated = VALUES(lastUpdated)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['ril100'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['weight'] ?? null,
            isset($data['products']) ? json_encode($data['products']) : null,
            $data['lastUpdated'],
        ]);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM TravelStop');
        return (int) $stmt->fetchColumn();
    }

    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM TravelStop');
    }

    public function findOldestStale(int $maxAgeHours = 168): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM TravelStop WHERE lastUpdated < DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY lastUpdated ASC LIMIT 100'
        );
        $stmt->execute([$maxAgeHours]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
