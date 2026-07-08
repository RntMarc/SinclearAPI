<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class EventRelationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id AS relationId, r.userId, r.eventId, r.createdAt,
                    u.email, u.displayName, u.image
             FROM EventRelation r
             JOIN User u ON u.id = r.userId
             WHERE r.eventId = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addParticipant(string $eventId, string $userId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO EventRelation (id, eventId, userId, createdAt)
             VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $eventId, $userId]);
        return $id;
    }

    public function removeByEventAndUser(string $eventId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM EventRelation WHERE eventId = ? AND userId = ?'
        );
        $stmt->execute([$eventId, $userId]);
    }
}
