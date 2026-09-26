<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Support\DateTimeValue;

final readonly class TravelTripRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelTrip WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM TravelTrip ORDER BY COALESCE(startAt, TIMESTAMP(startDate)) DESC, id ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM TravelTrip');
        return (int) $stmt->fetchColumn();
    }

    public function findByParticipant(string $userId, int $page, int $limit): array
    {
        $params = [$userId];

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT t.id)
             FROM TravelTrip t
             JOIN TravelRelation r ON r.tripid = t.id
             WHERE r.userid = ?'
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT DISTINCT t.*
             FROM TravelTrip t
             JOIN TravelRelation r ON r.tripid = t.id
             WHERE r.userid = ?
             ORDER BY COALESCE(t.startAt, TIMESTAMP(t.startDate)) DESC, t.id ASC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $trips = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $trips,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelTrip (id, name, description, allDay, timezone, startAt, endAt, startDate, endDate, hastickets, ticket, ticketUrl, forumId)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['allDay'] ?? 1),
            DateTimeValue::normalizeTimeZone($data['timezone'] ?? null),
            self::instant($data['startAt'] ?? null),
            self::instant($data['endAt'] ?? null),
            self::date($data['startDate'] ?? null),
            self::date($data['endDate'] ?? null),
            $data['hastickets'] ?? '0',
            $data['ticket'] ?? null,
            $data['ticketUrl'] ?? null,
            $data['forumId'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['name', 'description', 'allDay', 'timezone', 'startAt', 'endAt', 'startDate', 'endDate', 'hastickets', 'ticket', 'ticketUrl', 'forumId'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "`$field` = ?";
            $values[] = match ($field) {
                'allDay' => (int) $data[$field],
                'timezone' => DateTimeValue::normalizeTimeZone($data[$field]),
                'startAt', 'endAt' => self::instant($data[$field]),
                'startDate', 'endDate' => self::date($data[$field]),
                default => $data[$field],
            };
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE TravelTrip SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelTrip WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function findByForumId(string $forumId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelTrip WHERE forumId = ? LIMIT 1');
        $stmt->execute([$forumId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Alle Reisen des Nutzers (ueber TravelRelation), die den Zeitraum
     * ueberlappen. Der Zeitraum ist in zivilen Tagen der Zone
     * `$rangeTimezone` definiert.
     *
     * @return list<array<string, mixed>>
     */
    public function findByParticipantInRange(string $userId, string $start, string $end, string $rangeTimezone, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT t.*
             FROM TravelTrip t
             JOIN TravelRelation r ON r.tripid = t.id
             WHERE r.userid = ?
               AND (
                 (t.allDay = 0 AND t.endAt >= ?)
                 OR (t.allDay = 1 AND t.endDate >= ?)
               )
               AND (
                 (t.allDay = 0 AND t.startAt < ?)
                 OR (t.allDay = 1 AND t.startDate <= ?)
               )
             ORDER BY COALESCE(t.startAt, TIMESTAMP(t.startDate)) ASC, t.id ASC
             LIMIT ?'
        );
        $stmt->execute([
            $userId,
            DateTimeValue::civilDayStartUtc($start, $rangeTimezone),
            DateTimeValue::formatDate(DateTimeValue::parseDate($start)),
            DateTimeValue::civilDayEndUtcExclusive($end, $rangeTimezone),
            DateTimeValue::formatDate(DateTimeValue::parseDate($end)),
            $limit,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function instant(DateTimeImmutable|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return DateTimeValue::toDatabase($value);
        }

        try {
            return DateTimeValue::toDatabase(DateTimeValue::parseInstant($value));
        } catch (\InvalidArgumentException) {
            $instant = DateTimeValue::fromDatabase($value);
            if ($instant === null) {
                throw new \InvalidArgumentException('Invalid datetime');
            }

            return DateTimeValue::toDatabase($instant);
        }
    }

    private static function date(DateTimeImmutable|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DateTimeValue::formatDate(
            $value instanceof DateTimeImmutable ? $value : DateTimeValue::parseDate($value),
        );
    }
}
