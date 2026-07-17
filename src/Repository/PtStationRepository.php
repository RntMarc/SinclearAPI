<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

final readonly class PtStationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PtStation WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PtStation WHERE name LIKE ? ORDER BY name LIMIT ?'
        );
        $stmt->execute(['%' . $query . '%', $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(array $station): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO PtStation (id, name, latitude, longitude, lastUpdated)
             VALUES (?, ?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE
               name = VALUES(name),
               latitude = VALUES(latitude),
               longitude = VALUES(longitude),
               lastUpdated = VALUES(lastUpdated)'
        );
        $stmt->execute([
            $station['id'],
            $station['name'],
            $station['latitude'] ?? null,
            $station['longitude'] ?? null,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $stations
     */
    public function upsertBatch(array $stations): int
    {
        $count = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($stations as $station) {
                $this->upsert($station);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PtStation WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM PtStation');
        return (int) $stmt->fetchColumn();
    }
}
