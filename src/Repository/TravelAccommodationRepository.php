<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelAccommodationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.*
             FROM TravelAccommodation a
             JOIN TravelRelation r ON r.accommodation = a.ID
             WHERE r.tripid = ?
             ORDER BY a.name ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelAccommodation WHERE ID = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*
             FROM TravelAccommodation a
             JOIN TravelRelation r ON r.accommodation = a.ID
             WHERE a.ID = ? AND r.tripid = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
