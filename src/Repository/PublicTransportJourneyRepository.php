<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PublicTransportJourneyRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelPublicTransportJourney WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array{data: list<array>, meta: array} */
    public function findByUser(string $userId, ?string $tripId, int $page, int $limit): array
    {
        $conditions = ['j.creatorId = ?'];
        $params = [$userId];

        if ($tripId !== null) {
            $conditions[] = 'j.tripId = ?';
            $params[] = $tripId;
        }

        $where = implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM TravelPublicTransportJourney j
             JOIN TravelPublicTransportParticipant p ON p.journeyId = j.id
             WHERE $where"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT j.*
             FROM TravelPublicTransportJourney j
             JOIN TravelPublicTransportParticipant p ON p.journeyId = j.id
             WHERE $where
             GROUP BY j.id
             ORDER BY j.chosenAt DESC
             LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $journeys = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $journeys,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /** @return list<array> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.*
             FROM TravelPublicTransportJourney j
             WHERE j.tripId = ?
             ORDER BY j.chosenAt DESC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isParticipant(string $userId, string $journeyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM TravelPublicTransportParticipant WHERE userId = ? AND journeyId = ?'
        );
        $stmt->execute([$userId, $journeyId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function isCreator(string $userId, string $journeyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM TravelPublicTransportJourney WHERE id = ? AND creatorId = ?'
        );
        $stmt->execute([$journeyId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $creatorId, array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPublicTransportJourney (id, tripId, creatorId, refreshToken, chosenAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['tripId'] ?? null,
            $creatorId,
            $data['refreshToken'] ?? null,
            $data['chosenAt'] ?? date('Y-m-d H:i:s'),
        ]);

        $this->addParticipant($id, $creatorId);

        if (!empty($data['participantIds'])) {
            foreach ($data['participantIds'] as $pid) {
                if ($pid !== $creatorId) {
                    $this->addParticipant($id, $pid);
                }
            }
        }

        return $id;
    }

    public function addParticipant(string $journeyId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO TravelPublicTransportParticipant (journeyId, userId, addedAt)
             VALUES (?, ?, NOW(3))'
        );
        $stmt->execute([$journeyId, $userId]);
    }

    public function removeParticipant(string $journeyId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelPublicTransportParticipant WHERE journeyId = ? AND userId = ?'
        );
        $stmt->execute([$journeyId, $userId]);
    }

    /** @return list<array> */
    public function getParticipants(string $journeyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM TravelPublicTransportParticipant p
             JOIN User u ON u.id = p.userId
             WHERE p.journeyId = ?
             ORDER BY p.addedAt ASC'
        );
        $stmt->execute([$journeyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM TravelPublicTransportLeg WHERE journeyId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM TravelPublicTransportParticipant WHERE journeyId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM TravelPublicTransportJourney WHERE id = ?')->execute([$id]);
    }

    /** @return list<array> */
    public function getLegs(string $journeyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPublicTransportLeg WHERE journeyId = ? ORDER BY legIndex ASC'
        );
        $stmt->execute([$journeyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createLeg(string $journeyId, array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPublicTransportLeg
             (id, journeyId, legIndex, mode, lineName, lineProduct,
              originStopId, destinationStopId, originStopName, destinationStopName,
              dbTripId, plannedDeparture, plannedArrival,
              actualDeparture, actualArrival, departureDelay, arrivalDelay,
              departurePlatform, arrivalPlatform, cancelled, status,
              rawResponse, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $journeyId,
            $data['legIndex'] ?? 0,
            $data['mode'],
            $data['lineName'] ?? null,
            $data['lineProduct'] ?? null,
            $data['originStopId'],
            $data['destinationStopId'],
            $data['originStopName'] ?? '',
            $data['destinationStopName'] ?? '',
            $data['dbTripId'] ?? null,
            $data['plannedDeparture'],
            $data['plannedArrival'],
            $data['actualDeparture'] ?? null,
            $data['actualArrival'] ?? null,
            $data['departureDelay'] ?? null,
            $data['arrivalDelay'] ?? null,
            $data['departurePlatform'] ?? null,
            $data['arrivalPlatform'] ?? null,
            $data['cancelled'] ?? 0,
            $data['status'] ?? 'planned',
            isset($data['rawResponse']) ? json_encode($data['rawResponse']) : null,
        ]);

        return $id;
    }

    public function updateLeg(string $legId, array $data): void
    {
        $sets = [];
        $values = [];

        foreach ([
            'actualDeparture', 'actualArrival', 'departureDelay', 'arrivalDelay',
            'departurePlatform', 'arrivalPlatform', 'cancelled', 'status',
            'rawResponse', 'lastCheckedAt',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $field === 'rawResponse' && is_array($data[$field])
                    ? json_encode($data[$field])
                    : $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $legId;
        $sql = 'UPDATE TravelPublicTransportLeg SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    /** @return list<array> */
    public function findStaleLegs(int $maxAgeMinutes = 15): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, j.creatorId
             FROM TravelPublicTransportLeg l
             JOIN TravelPublicTransportJourney j ON j.id = l.journeyId
             WHERE l.dbTripId IS NOT NULL
               AND l.status NOT IN ("arrived", "cancelled")
               AND (l.lastCheckedAt IS NULL OR l.lastCheckedAt < DATE_SUB(NOW(), INTERVAL ? MINUTE))
             ORDER BY l.plannedArrival ASC
             LIMIT 100'
        );
        $stmt->execute([$maxAgeMinutes]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
