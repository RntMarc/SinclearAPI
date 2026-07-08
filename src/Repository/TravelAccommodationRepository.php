<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class TravelAccommodationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM TravelAccommodation ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.*
             FROM TravelAccommodation a
             JOIN TravelRelation r ON r.accommodation = a.ID
             WHERE r.tripid = ?
             ORDER BY a.name ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelAccommodation WHERE ID = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*
             FROM TravelAccommodation a
             JOIN TravelRelation r ON r.accommodation = a.ID
             WHERE a.ID = ? AND r.tripid = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findUsersByAccommodation(string $accommodationId, string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM TravelRelation r
             JOIN User u ON u.id = r.userid
             WHERE r.accommodation = ? AND r.tripid = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$accommodationId, $tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelAccommodation (ID, name, description, address, OSMID, latitude, longitude, phone, mail, ishotel)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? null,
            $data['address'] ?? null,
            $data['OSMID'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['phone'] ?? null,
            $data['mail'] ?? null,
            $data['ishotel'] ?? 0,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['name', 'description', 'address', 'OSMID', 'latitude', 'longitude', 'phone', 'mail', 'ishotel'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`$field` = ?";
                $values[] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE TravelAccommodation SET ' . implode(', ', $sets) . ' WHERE ID = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelAccommodation WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
