<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Rueckmeldung je Terminoption und aktivem Planungsmitglied
 * (TravelPlanDateResponse). availability: yes | maybe | no
 */
final readonly class TravelPlanDateResponseRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByOption(string $dateOptionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanDateResponse WHERE dateOptionId = ?'
        );
        $stmt->execute([$dateOptionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*
             FROM TravelPlanDateResponse r
             JOIN TravelPlanDateOption o ON o.id = r.dateOptionId
             WHERE o.tripId = ?'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(string $dateOptionId, string $userId, string $availability): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanDateResponse (id, dateOptionId, userId, availability)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE availability = VALUES(availability)'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $dateOptionId, $userId, $availability]);
    }

    public function deleteByTrip(string $tripId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE r FROM TravelPlanDateResponse r
             JOIN TravelPlanDateOption o ON o.id = r.dateOptionId
             WHERE o.tripId = ?'
        );
        $stmt->execute([$tripId]);
    }
}
