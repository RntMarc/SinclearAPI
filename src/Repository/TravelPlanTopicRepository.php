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

    /**
     * Legt das Thema an oder aktualisiert seinen Status (rennsicher: ein
     * Statement, Unique-Key tripId+topic).
     */
    public function upsert(string $tripId, string $topic, string $status = 'pending'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanTopic (id, tripId, topic, status)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $tripId, $topic, $status]);
    }
}
