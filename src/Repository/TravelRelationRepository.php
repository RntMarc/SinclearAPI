<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelRelationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function isParticipant(string $userId, string $tripId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM TravelRelation WHERE userid = ? AND tripid = ? LIMIT 1'
        );
        $stmt->execute([$userId, $tripId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function findParticipantsByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.displayName, u.image
             FROM TravelRelation r
             JOIN User u ON u.id = r.userid
             WHERE r.tripid = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
