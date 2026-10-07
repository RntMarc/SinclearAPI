<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Teilnahmeinteresse an Tagesprogramm-Vorschlaegen
 * (TravelPlanEventInterest). interest: yes | maybe | no
 */
final readonly class TravelPlanEventInterestRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findBySuggestion(string $eventSuggestionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanEventInterest WHERE eventSuggestionId = ?'
        );
        $stmt->execute([$eventSuggestionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*
             FROM TravelPlanEventInterest i
             JOIN TravelPlanEvent e ON e.id = i.eventSuggestionId
             WHERE e.tripId = ?'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(string $eventSuggestionId, string $userId, string $interest): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanEventInterest (id, eventSuggestionId, userId, interest)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE interest = VALUES(interest)'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $eventSuggestionId, $userId, $interest]);
    }

    public function deleteByTrip(string $tripId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE i FROM TravelPlanEventInterest i
             JOIN TravelPlanEvent e ON e.id = i.eventSuggestionId
             WHERE e.tripId = ?'
        );
        $stmt->execute([$tripId]);
    }
}
