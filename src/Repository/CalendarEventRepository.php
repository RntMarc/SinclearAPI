<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class CalendarEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(string $creatorId, array $data): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO CalendarEvent (id, creatorId, title, description, startTime, endTime, visibility, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $creatorId,
            $data['title'],
            $data['description'] ?? null,
            $this->formatDatetime($data['startTime']),
            $this->formatDatetime($data['endTime']),
            $data['visibility'] ?? 0,
        ]);

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $fields = [];
        $params = [];

        foreach (['title', 'description', 'startTime', 'endTime', 'visibility'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = ?";
                $value = $data[$field];
                if (in_array($field, ['startTime', 'endTime'], true)) {
                    $value = $this->formatDatetime($value);
                }
                $params[] = $value;
            }
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
        $stmt = $this->pdo->prepare('SELECT * FROM CalendarEvent WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findAllVisible(
        string $userId,
        ?string $start,
        ?string $end,
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
            $timeConditions[] = 'e.endTime > ?';
            $params[] = $this->formatDatetime($start);
        }
        if ($end !== null) {
            $timeConditions[] = 'e.startTime < ?';
            $params[] = $this->formatDatetime($end);
        }

        $where = $visibilityWhere;
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
            "SELECT e.* FROM CalendarEvent e WHERE $where ORDER BY e.startTime ASC LIMIT ? OFFSET ?"
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

    public function addParticipant(string $eventId, string $userId): void
    {
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

    private function formatDatetime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new RuntimeException('Invalid datetime');
        }
    }
}
