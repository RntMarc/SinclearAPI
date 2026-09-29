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

    /**
     * Globaler Katalog wiederverwendbarer Unterkuenfte. Optional nach Name
     * gefiltert.
     *
     * @return list<array<string, mixed>>
     */
    public function findCatalog(?string $query = null): array
    {
        $query = $query !== null ? trim($query) : '';
        if ($query === '') {
            return $this->findAll();
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelAccommodation WHERE name LIKE ? ORDER BY name ASC LIMIT 200'
        );
        $stmt->execute(['%' . $query . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.*
             FROM TravelAccommodation a
             LEFT JOIN TravelRelation r ON r.accommodation = a.ID AND r.tripid = ?
             LEFT JOIN TravelAccommodationTrip t ON t.accommodationId = a.ID AND t.tripid = ?
             WHERE a.tripId = ? OR r.ID IS NOT NULL OR t.ID IS NOT NULL
             ORDER BY a.name ASC'
        );
        $stmt->execute([$tripId, $tripId, $tripId]);
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
            'SELECT DISTINCT a.*
             FROM TravelAccommodation a
             LEFT JOIN TravelRelation r ON r.accommodation = a.ID AND r.tripid = ?
             LEFT JOIN TravelAccommodationTrip t ON t.accommodationId = a.ID AND t.tripid = ?
             WHERE a.ID = ? AND (a.tripId = ? OR r.ID IS NOT NULL OR t.ID IS NOT NULL)
             LIMIT 1'
        );
        $stmt->execute([$tripId, $tripId, $id, $tripId]);
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

    public function isLinkedToTrip(string $tripId, string $accommodationId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM TravelAccommodationTrip WHERE tripid = ? AND accommodationId = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $accommodationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function linkToTrip(string $tripId, string $accommodationId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO TravelAccommodationTrip (ID, tripid, accommodationId) VALUES (?, ?, ?)'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $tripId, $accommodationId]);
    }

    /**
     * Verknuepft eine Katalog-Unterkunft mit einer Reise und hinterlegt den
     * reise-spezifischen Preis (wird bei der Aktivierung aus der gewaehlten
     * Planungsoption uebernommen). Bestehende Verknuepfungen werden
     * aktualisiert (idempotent).
     */
    public function linkToTripWithPrice(
        string $tripId,
        string $accommodationId,
        ?string $pricePerPersonPerNight,
        ?string $currency,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelAccommodationTrip (ID, tripid, accommodationId, pricePerPersonPerNight, currency)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                pricePerPersonPerNight = VALUES(pricePerPersonPerNight),
                currency = VALUES(currency)'
        );
        $stmt->execute([
            Uuid::uuid7()->toString(),
            $tripId,
            $accommodationId,
            $pricePerPersonPerNight,
            $currency,
        ]);
    }

    public function unlinkFromTrip(string $tripId, string $accommodationId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelAccommodationTrip WHERE tripid = ? AND accommodationId = ?'
        );
        $stmt->execute([$tripId, $accommodationId]);
    }

    public function deleteAllLinks(string $accommodationId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM TravelAccommodationTrip WHERE accommodationId = ?'
        );
        $stmt->execute([$accommodationId]);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelAccommodation (ID, name, description, address, OSMID, latitude, longitude, phone, mail, ishotel, citySlug, tripId, createdBy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $data['citySlug'] ?? null,
            $data['tripId'] ?? null,
            $data['createdBy'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['name', 'description', 'address', 'OSMID', 'latitude', 'longitude', 'phone', 'mail', 'ishotel', 'citySlug'] as $field) {
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
