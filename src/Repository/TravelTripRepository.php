<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

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
        $stmt = $this->pdo->query('SELECT * FROM TravelTrip ORDER BY start DESC');
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
             ORDER BY t.start DESC
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
            'INSERT INTO TravelTrip (id, name, description, start, end, hastickets, ticket, ticketUrl, forumId)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? null,
            $data['start'],
            $data['end'],
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

        foreach (['name', 'description', 'start', 'end', 'hastickets', 'ticket', 'ticketUrl', 'forumId'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
            }
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
}
