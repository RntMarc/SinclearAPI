<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

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

    public function findParticipantRelationsByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.ID AS relationId, r.userid, r.tripid, r.accommodation,
                    u.email, u.displayName, u.image,
                    a.name AS accommodationName
             FROM TravelRelation r
             JOIN User u ON u.id = r.userid
             LEFT JOIN TravelAccommodation a ON a.ID = r.accommodation
             WHERE r.tripid = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addParticipant(string $userId, string $tripId, ?string $accommodationId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelRelation (ID, userid, tripid, accommodation)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $userId, $tripId, $accommodationId]);
        return $id;
    }

    public function removeByUserAndTrip(string $userId, string $tripId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelRelation WHERE userid = ? AND tripid = ?'
        );
        $stmt->execute([$userId, $tripId]);
    }

    public function updateAccommodation(string $userId, string $tripId, ?string $accommodationId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelRelation SET accommodation = ? WHERE userid = ? AND tripid = ?'
        );
        $stmt->execute([$accommodationId, $userId, $tripId]);
    }
}
