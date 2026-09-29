<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Transportpraeferenz je Planungsmitglied und Richtung (TravelPlanTransport).
 * direction: outbound | return
 */
final readonly class TravelPlanTransportRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.displayName, u.image
             FROM TravelPlanTransport t
             JOIN User u ON u.id = t.userId
             WHERE t.tripId = ?
             ORDER BY u.displayName ASC, t.direction ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTripAndUserAndDirection(string $tripId, string $userId, string $direction): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanTransport WHERE tripId = ? AND userId = ? AND direction = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $userId, $direction]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @param array<string, mixed> $data */
    public function upsert(string $tripId, string $userId, string $direction, array $data): void
    {
        $existing = $this->findByTripAndUserAndDirection($tripId, $userId, $direction);
        if ($existing !== null) {
            $sets = [];
            $values = [];
            foreach (['mode', 'offersRide', 'availableSeats', 'notes'] as $field) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $sets[] = "`$field` = ?";
                $values[] = match ($field) {
                    'offersRide' => (int) $data[$field],
                    'availableSeats' => $data[$field] === null ? null : (int) $data[$field],
                    default => $data[$field],
                };
            }
            if ($sets === []) {
                return;
            }
            $values[] = $existing['id'];
            $stmt = $this->pdo->prepare('UPDATE TravelPlanTransport SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($values);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanTransport (id, tripId, userId, direction, mode, offersRide, availableSeats, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Uuid::uuid7()->toString(),
            $tripId,
            $userId,
            $direction,
            $data['mode'] ?? null,
            (int) ($data['offersRide'] ?? 0),
            isset($data['availableSeats']) ? (int) $data['availableSeats'] : null,
            $data['notes'] ?? null,
        ]);
    }
}
