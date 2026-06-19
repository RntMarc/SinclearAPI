<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEvent WHERE trip = ? ORDER BY start ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelEvent WHERE ID = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEvent WHERE ID = ? AND trip = ?'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
