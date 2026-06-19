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

    public function findStandaloneByParticipant(string $userId, int $page, int $limit): array
    {
        $params = [$userId];

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT e.ID)
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.trip IS NULL AND r.userId = ?'
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT DISTINCT e.*
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.trip IS NULL AND r.userId = ?
             ORDER BY e.start DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $events = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $events,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findStandaloneByIdAndParticipant(string $eventId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.ID = ? AND e.trip IS NULL AND r.userId = ?
             LIMIT 1'
        );
        $stmt->execute([$eventId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findParticipantsByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM EventRelation r
             JOIN User u ON u.id = r.userId
             WHERE r.eventId = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
