<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Support\DateTimeValue;

final readonly class CalendarEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(string $creatorId, array $data): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO CalendarEvent (id, creatorId, title, description, allDay, timezone, startAt, endAt, startDate, endDate, visibility, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $creatorId,
            $data['title'],
            $data['description'] ?? null,
            (int) ($data['allDay'] ?? 0),
            DateTimeValue::normalizeTimeZone($data['timezone'] ?? null),
            self::instant($data['startAt'] ?? null),
            self::instant($data['endAt'] ?? null),
            self::date($data['startDate'] ?? null),
            self::date($data['endDate'] ?? null),
            (int) ($data['visibility'] ?? 0),
        ]);

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $fields = [];
        $params = [];

        foreach (['title', 'description', 'allDay', 'timezone', 'startAt', 'endAt', 'startDate', 'endDate', 'visibility'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $fields[] = "`$field` = ?";
            $params[] = match ($field) {
                'allDay', 'visibility' => (int) $data[$field],
                'timezone' => DateTimeValue::normalizeTimeZone($data[$field]),
                'startAt', 'endAt' => self::instant($data[$field]),
                'startDate', 'endDate' => self::date($data[$field]),
                default => $data[$field],
            };
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updatedAt = NOW(3)';
        $params[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE CalendarEvent SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM CalendarEventParticipant WHERE eventId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM CalendarEvent WHERE id = ?')->execute([$id]);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM CalendarEvent e
             LEFT JOIN User u ON u.id = e.creatorId
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Sichtbare Events im Zeitraum. Der Zeitraum ist in zivilen Tagen der
     * Zone `$rangeTimezone` definiert: Getaktete Eintraege werden gegen ihre
     * UTC-Instants geprueft, ganztägige gegen ihre zivilen Tage.
     */
    public function findAllVisible(
        string $userId,
        ?string $start,
        ?string $end,
        string $rangeTimezone,
        int $page,
        int $limit,
    ): array {
        $conditions = [
            'e.creatorId = ?',
            'EXISTS (SELECT 1 FROM CalendarEventParticipant p WHERE p.eventId = e.id AND p.userId = ?)',
            'e.visibility = 1',
            '(e.visibility = 2 AND EXISTS (SELECT 1 FROM CloseFriend cf WHERE cf.userId = e.creatorId AND cf.friendId = ?))',
        ];
        $params = [$userId, $userId, $userId];

        $visibilityWhere = '(' . implode(') OR (', $conditions) . ')';

        $timeConditions = [];
        if ($start !== null) {
            $timeConditions[] = '((e.allDay = 0 AND e.endAt >= ?) OR (e.allDay = 1 AND e.endDate >= ?))';
            $params[] = DateTimeValue::civilDayStartUtc($start, $rangeTimezone);
            $params[] = DateTimeValue::formatDate(DateTimeValue::parseDate($start));
        }
        if ($end !== null) {
            $timeConditions[] = '((e.allDay = 0 AND e.startAt < ?) OR (e.allDay = 1 AND e.startDate <= ?))';
            $params[] = DateTimeValue::civilDayEndUtcExclusive($end, $rangeTimezone);
            $params[] = DateTimeValue::formatDate(DateTimeValue::parseDate($end));
        }

        $where = '(' . $visibilityWhere . ')';
        if ($timeConditions !== []) {
            $where .= ' AND ' . implode(' AND ', $timeConditions);
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM CalendarEvent e WHERE $where"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT e.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM CalendarEvent e
             LEFT JOIN User u ON u.id = e.creatorId
             WHERE $where ORDER BY COALESCE(e.startAt, TIMESTAMP(e.startDate)) ASC, e.id ASC LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $events = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $events,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Liefert Teilnehmer mehrerer Events gruppiert nach Event-ID.
     *
     * @param list<string> $eventIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function findParticipantsForEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.eventId, u.id, u.displayName
             FROM CalendarEventParticipant p
             JOIN User u ON u.id = p.userId
             WHERE p.eventId IN ($placeholders)
             ORDER BY u.displayName ASC"
        );
        $stmt->execute($eventIds);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['eventId']][] = [
                'id' => $row['id'],
                'displayName' => $row['displayName'],
            ];
        }
        return $result;
    }

    public function addParticipant(string $eventId, string $userId): void    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO CalendarEventParticipant (eventId, userId, addedAt) VALUES (?, ?, NOW(3))'
        );
        $stmt->execute([$eventId, $userId]);
    }

    public function removeParticipant(string $eventId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM CalendarEventParticipant WHERE eventId = ? AND userId = ?'
        );
        $stmt->execute([$eventId, $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function findParticipantsByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM CalendarEventParticipant p
             JOIN User u ON u.id = p.userId
             WHERE p.eventId = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findParticipantIdsByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT userId FROM CalendarEventParticipant WHERE eventId = ?'
        );
        $stmt->execute([$eventId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'userId');
    }

    public function isParticipant(string $eventId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM CalendarEventParticipant WHERE eventId = ? AND userId = ?'
        );
        $stmt->execute([$eventId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
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
