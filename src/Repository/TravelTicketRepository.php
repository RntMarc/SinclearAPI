<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class TravelTicketRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelEventTicket WHERE ID = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEventTicket WHERE type = ? AND trip = ?',
        );
        $stmt->execute(['trip', $tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEventTicket WHERE type = ? AND event = ?',
        );
        $stmt->execute(['event', $eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEventTicket WHERE type = ? AND `user` = ?',
        );
        $stmt->execute(['user', $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByUserAndTrip(string $userId, string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEventTicket WHERE type = ? AND `user` = ? AND trip = ?',
        );
        $stmt->execute(['user', $userId, $tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findByUserAndEvent(string $userId, string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEventTicket WHERE type = ? AND `user` = ? AND event = ?',
        );
        $stmt->execute(['user', $userId, $eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelEventTicket (ID, type, event, trip, `user`, qrcode, image)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $id,
            $data['type'],
            $data['event'] ?? null,
            $data['trip'] ?? null,
            $data['user'] ?? null,
            $data['qrcode'] ?? null,
            $data['image'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['type', 'event', 'trip', 'user', 'qrcode', 'image'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`$field` = ?";
                $values[] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE TravelEventTicket SET ' . implode(', ', $sets) . ' WHERE ID = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelEventTicket WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
