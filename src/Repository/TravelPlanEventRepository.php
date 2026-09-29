<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Tagesprogramm-Vorschlaege einer Planungsreise ("Planungs-Events",
 * TravelPlanEvent). Erst bestaetigte Vorschlaege (`isConfirmed`) werden bei
 * der Aktivierung zu operativen TravelEvent-Datensaetzen; `confirmedEventId`
 * verweist dann auf das erzeugte Event (Idempotenz).
 *
 * Zeitangaben werden wie bei TravelPlanDateOption vom Service bereits
 * DB-fertig normalisiert.
 */
final readonly class TravelPlanEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanEvent WHERE tripId = ? ORDER BY dayIndex ASC, createdAt ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanEvent WHERE id = ? AND tripId = ? LIMIT 1'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function findConfirmedByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanEvent WHERE tripId = ? AND isConfirmed = 1 ORDER BY dayIndex ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanEvent
                (id, tripId, name, description, dayIndex, allDay, timezone, startAt, endAt,
                 startDate, endDate, address, latitude, longitude, OSMID, citySlug, proposedBy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['tripId'],
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['dayIndex'] ?? 0),
            (int) ($data['allDay'] ?? 0),
            $data['timezone'] ?? 'Europe/Berlin',
            $data['startAt'] ?? null,
            $data['endAt'] ?? null,
            $data['startDate'] ?? null,
            $data['endDate'] ?? null,
            $data['address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['OSMID'] ?? null,
            $data['citySlug'] ?? null,
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
            'name', 'description', 'dayIndex', 'allDay', 'timezone', 'startAt', 'endAt',
            'startDate', 'endDate', 'address', 'latitude', 'longitude', 'OSMID', 'citySlug',
        ] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $sets[] = "`$field` = ?";
            $values[] = match ($field) {
                'dayIndex', 'allDay' => (int) $data[$field],
                default => $data[$field],
            };
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $stmt = $this->pdo->prepare('UPDATE TravelPlanEvent SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelPlanEvent WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function setConfirmed(string $id, string $eventId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanEvent SET isConfirmed = 1, confirmedEventId = ? WHERE id = ?'
        );
        $stmt->execute([$eventId, $id]);
    }

    /**
     * Bestaetigung durch die Leitung setzen oder zuruecknehmen. Beim
     * Zuruecknehmen wird eine eventuell gesetzte `confirmedEventId`
     * entfernt (relevant nur vor der Aktivierung).
     */
    public function setConfirmation(string $id, bool $confirmed): void
    {
        if ($confirmed) {
            $stmt = $this->pdo->prepare('UPDATE TravelPlanEvent SET isConfirmed = 1 WHERE id = ?');
            $stmt->execute([$id]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanEvent SET isConfirmed = 0, confirmedEventId = NULL WHERE id = ?'
        );
        $stmt->execute([$id]);
    }
}
