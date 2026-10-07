<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Unterkunftsoptionen einer Planungsreise (TravelPlanAccommodationOption),
 * inkl. Preis pro Person und Nacht. `isSelected` markiert die von der
 * Leitung gewaehlte Option.
 */
final readonly class TravelPlanAccommodationOptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanAccommodationOption WHERE tripId = ? ORDER BY createdAt ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanAccommodationOption WHERE id = ? AND tripId = ? LIMIT 1'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findSelectedByTrip(string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanAccommodationOption WHERE tripId = ? AND isSelected = 1 LIMIT 1'
        );
        $stmt->execute([$tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanAccommodationOption
                (id, tripId, accommodationId, name, description, address, OSMID, latitude, longitude,
                 citySlug, pricePerPersonPerNight, currency, proposedBy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['tripId'],
            $data['accommodationId'] ?? null,
            $data['name'] ?? null,
            $data['description'] ?? null,
            $data['address'] ?? null,
            $data['OSMID'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['citySlug'] ?? null,
            $data['pricePerPersonPerNight'] ?? null,
            $data['currency'] ?? null,
            $data['proposedBy'] ?? null,
        ]);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        foreach ([
            'accommodationId', 'name', 'description', 'address', 'OSMID', 'latitude',
            'longitude', 'citySlug', 'pricePerPersonPerNight', 'currency',
        ] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $sets[] = "`$field` = ?";
            $values[] = $data[$field];
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $stmt = $this->pdo->prepare('UPDATE TravelPlanAccommodationOption SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelPlanAccommodationOption WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByTrip(string $tripId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelPlanAccommodationOption WHERE tripId = ?');
        $stmt->execute([$tripId]);
    }

    /**
     * Markiert atomar genau eine Unterkunftsoption der Reise als gewaehlt und
     * setzt alle uebrigen auf nicht-gewaehlt (Rennsicherheit: ein Statement).
     */
    public function setSelectedExclusive(string $tripId, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanAccommodationOption SET isSelected = (id = ?) WHERE tripId = ?'
        );
        $stmt->execute([$id, $tripId]);
    }
}
