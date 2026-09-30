<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Terminoptionen einer Planungsreise (TravelPlanDateOption).
 *
 * Zeitangaben sind zeitzonenbewusst und werden vom Service bereits
 * DB-fertig normalisiert (startAt/endAt als UTC-Instant, startDate/endDate
 * als zivile Tage). `isFinal` markiert die von der Leitung festgelegte
 * Option; der Service stellt sicher, dass hoechstens eine Option je Reise
 * `isFinal` traegt.
 */
final readonly class TravelPlanDateOptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanDateOption WHERE tripId = ? ORDER BY position ASC, createdAt ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanDateOption WHERE id = ? AND tripId = ? LIMIT 1'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findFinalByTrip(string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanDateOption WHERE tripId = ? AND isFinal = 1 LIMIT 1'
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
            'INSERT INTO TravelPlanDateOption
                (id, tripId, label, allDay, timezone, startAt, endAt, startDate, endDate, proposedBy, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['tripId'],
            $data['label'] ?? null,
            (int) ($data['allDay'] ?? 1),
            $data['timezone'] ?? 'Europe/Berlin',
            $data['startAt'] ?? null,
            $data['endAt'] ?? null,
            $data['startDate'] ?? null,
            $data['endDate'] ?? null,
            $data['proposedBy'] ?? null,
            (int) ($data['position'] ?? 0),
        ]);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        foreach (['label', 'allDay', 'timezone', 'startAt', 'endAt', 'startDate', 'endDate', 'position'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $sets[] = "`$field` = ?";
            $values[] = match ($field) {
                'allDay', 'position' => (int) $data[$field],
                default => $data[$field],
            };
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $stmt = $this->pdo->prepare('UPDATE TravelPlanDateOption SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelPlanDateOption WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Markiert atomar genau eine Terminoption der Reise als final und setzt
     * alle uebrigen auf nicht-final (Rennsicherheit: in einem Statement).
     */
    public function setFinalExclusive(string $tripId, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanDateOption SET isFinal = (id = ?) WHERE tripId = ?'
        );
        $stmt->execute([$id, $tripId]);
    }
}
