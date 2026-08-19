<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class TravelChatRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelChat WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByTripId(string $tripId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelChat WHERE tripId = ? LIMIT 1');
        $stmt->execute([$tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByEventId(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelChat WHERE eventId = ? LIMIT 1');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByConversationId(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelChat WHERE conversationId = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $conversationId, ?string $tripId, ?string $eventId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelChat (id, conversationId, tripId, eventId, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $conversationId, $tripId, $eventId]);
        return $id;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelChat WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByTripId(string $tripId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelChat WHERE tripId = ?');
        $stmt->execute([$tripId]);
    }

    public function deleteByEventId(string $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelChat WHERE eventId = ?');
        $stmt->execute([$eventId]);
    }
}
