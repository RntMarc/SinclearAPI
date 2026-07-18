<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PtJourneyRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PtJourney WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLegsByJourney(string $journeyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PtLeg WHERE journeyId = ? ORDER BY legIndex ASC'
        );
        $stmt->execute([$journeyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findParticipantsByJourney(string $journeyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM PtParticipant p
             JOIN User u ON u.id = p.userId
             WHERE p.journeyId = ?
             ORDER BY p.addedAt ASC'
        );
        $stmt->execute([$journeyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isParticipant(string $journeyId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM PtParticipant WHERE journeyId = ? AND userId = ?'
        );
        $stmt->execute([$journeyId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isCreator(string $journeyId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM PtJourney WHERE id = ? AND creatorId = ?'
        );
        $stmt->execute([$journeyId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByUser(string $userId, ?string $tripId = null, int $page = 1, int $limit = 20): array
    {
        $conditions = ['creatorId = ?'];
        $params = [$userId];

        if ($tripId !== null) {
            $conditions[] = 'tripId = ?';
            $params[] = $tripId;
        }

        $where = implode(' AND ', $conditions);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM PtJourney WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Data
        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT * FROM PtJourney WHERE {$where} ORDER BY departureTime DESC LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function create(array $data, array $legs, array $participantIds): array
    {
        $journeyId = Uuid::uuid7()->toString();
        $now = date('Y-m-d H:i:s');
        $nowMs = date('Y-m-d H:i:s.') . substr((string) microtime(true), strpos((string) microtime(true), '.') + 1, 3);

        $this->pdo->beginTransaction();

        try {
            // Journey
            $stmt = $this->pdo->prepare(
                'INSERT INTO PtJourney (id, tripId, creatorId, fromStationId, fromStationName, toStationId, toStationName, departureTime, arrivalTime, duration, transfers, chosenAt, createdAt, updatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
            );
            $stmt->execute([
                $journeyId,
                $data['tripId'] ?? null,
                $data['creatorId'],
                $data['fromStationId'],
                $data['fromStationName'],
                $data['toStationId'],
                $data['toStationName'],
                $data['departureTime'],
                $data['arrivalTime'],
                $data['duration'],
                $data['transfers'] ?? 0,
                $data['chosenAt'] ?? $now,
            ]);

            // Legs
            $legStmt = $this->pdo->prepare(
                'INSERT INTO PtLeg (id, journeyId, legIndex, mode, lineName, lineProduct, fromStationId, fromStationName, toStationId, toStationName, tripId, plannedDeparture, plannedArrival, cancelled, rawResponse, createdAt, updatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
            );

            foreach ($legs as $index => $leg) {
                $legId = Uuid::uuid7()->toString();
                $legStmt->execute([
                    $legId,
                    $journeyId,
                    $index,
                    $leg['mode'],
                    $leg['lineName'] ?? null,
                    $leg['lineProduct'] ?? null,
                    $leg['fromStationId'],
                    $leg['fromStationName'],
                    $leg['toStationId'],
                    $leg['toStationName'],
                    $leg['tripId'] ?? null,
                    $leg['plannedDeparture'],
                    $leg['plannedArrival'],
                    $leg['cancelled'] ?? false,
                    isset($leg['rawResponse']) ? json_encode($leg['rawResponse']) : null,
                ]);
            }

            // Participants (creator + additional)
            $partStmt = $this->pdo->prepare(
                'INSERT INTO PtParticipant (journeyId, userId, addedAt) VALUES (?, ?, NOW(3))'
            );

            // Creator is always a participant
            $partStmt->execute([$journeyId, $data['creatorId']]);

            // Additional participants
            foreach ($participantIds as $participantId) {
                if ($participantId !== $data['creatorId']) {
                    $partStmt->execute([$journeyId, $participantId]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->findById($journeyId);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PtJourney WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function addParticipant(string $journeyId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO PtParticipant (journeyId, userId, addedAt) VALUES (?, ?, NOW(3))'
        );
        $stmt->execute([$journeyId, $userId]);
    }

    public function removeParticipant(string $journeyId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM PtParticipant WHERE journeyId = ? AND userId = ?'
        );
        $stmt->execute([$journeyId, $userId]);
    }

    /**
     * Find legs that need refresh (stale legs with status not arrived/cancelled)
     *
     * @return list<array<string, mixed>>
     */
    public function findStaleLegs(int $maxAgeMinutes = 15): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.*, j.creatorId
             FROM PtLeg l
             JOIN PtJourney j ON j.id = l.journeyId
             WHERE l.tripId IS NOT NULL
               AND l.cancelled = 0
               AND (l.lastCheckedAt IS NULL OR l.lastCheckedAt < DATE_SUB(NOW(), INTERVAL ? MINUTE))
             ORDER BY l.lastCheckedAt ASC"
        );
        $stmt->execute([$maxAgeMinutes]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateLeg(string $legId, array $data): void
    {
        $sets = [];
        $params = [];

        foreach ($data as $key => $value) {
            $sets[] = "{$key} = ?";
            $params[] = $value;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $params[] = $legId;

        $stmt = $this->pdo->prepare(
            'UPDATE PtLeg SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }
}
