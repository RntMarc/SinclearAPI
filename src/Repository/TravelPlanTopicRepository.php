<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Phasenthemen einer Planungsreise (TravelPlanTopic).
 *
 * Die drei Themen sind serverseitig fest:
 *   participants = Datum und Teilnehmende
 *   travel       = Anreise, Abreise und Unterkunft
 *   program      = Tagesprogramm und Events
 *
 * Status: pending | in_progress | completed | skipped
 */
final readonly class TravelPlanTopicRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM TravelPlanTopic WHERE tripId = ?
             ORDER BY FIELD(topic, 'participants', 'travel', 'program')"
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTripAndTopic(string $tripId, string $topic): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanTopic WHERE tripId = ? AND topic = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $topic]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function createForTrip(string $tripId, string $topic, string $status = 'pending'): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanTopic (id, tripId, topic, status)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tripId, $topic, $status]);
        return $id;
    }

    public function updateStatus(string $tripId, string $topic, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanTopic SET status = ? WHERE tripId = ? AND topic = ?'
        );
        $stmt->execute([$status, $tripId, $topic]);
    }
}
