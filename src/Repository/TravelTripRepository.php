<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelTripRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelTrip WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByParticipant(string $userId, int $page, int $limit): array
    {
        $params = [$userId];

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT t.id)
             FROM TravelTrip t
             JOIN TravelRelation r ON r.tripid = t.id
             WHERE r.userid = ?'
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT DISTINCT t.*
             FROM TravelTrip t
             JOIN TravelRelation r ON r.tripid = t.id
             WHERE r.userid = ?
             ORDER BY t.start DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $trips = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $trips,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
