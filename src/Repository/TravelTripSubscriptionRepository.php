<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class TravelTripSubscriptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT subscriptionId FROM TravelTripSubscription WHERE tripId = ?'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findByTripWithUserAccess(string $tripId, string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, sr.id AS relationId, sr.isUser, sr.userName, sr.hasPaid
             FROM TravelTripSubscription tts
             JOIN Subscription s ON s.id = tts.subscriptionId
             JOIN SubscriptionRelation sr ON sr.subscriptionId = s.id AND sr.userId = ?
             WHERE tts.tripId = ?
             ORDER BY s.name ASC'
        );
        $stmt->execute([$userId, $tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByTrip(string $tripId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM TravelTripSubscription WHERE tripId = ?'
        );
        $stmt->execute([$tripId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(string $tripId, string $subscriptionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO TravelTripSubscription (tripId, subscriptionId) VALUES (?, ?)'
        );
        $stmt->execute([$tripId, $subscriptionId]);
    }

    public function remove(string $tripId, string $subscriptionId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelTripSubscription WHERE tripId = ? AND subscriptionId = ?'
        );
        $stmt->execute([$tripId, $subscriptionId]);
    }

    public function removeByTrip(string $tripId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelTripSubscription WHERE tripId = ?'
        );
        $stmt->execute([$tripId]);
    }

    public function findByForum(string $forumId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM TravelTrip WHERE forumId = ? LIMIT 1'
        );
        $stmt->execute([$forumId]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result ?: null;
    }
}
