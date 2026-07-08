<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class TravelEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM TravelEvent ORDER BY start DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEvent WHERE trip = ? ORDER BY start ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM TravelEvent WHERE ID = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndTrip(string $id, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelEvent WHERE ID = ? AND trip = ?'
        );
        $stmt->execute([$id, $tripId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findStandaloneByParticipant(string $userId, int $page, int $limit): array
    {
        $params = [$userId];

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT e.ID)
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.trip IS NULL AND r.userId = ?'
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT DISTINCT e.*
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.trip IS NULL AND r.userId = ?
             ORDER BY e.start DESC
             LIMIT ? OFFSET ?'
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

    public function findStandaloneByIdAndParticipant(string $eventId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*
             FROM TravelEvent e
             JOIN EventRelation r ON r.eventId = e.ID
             WHERE e.ID = ? AND e.trip IS NULL AND r.userId = ?
             LIMIT 1'
        );
        $stmt->execute([$eventId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findParticipantsByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image
             FROM EventRelation r
             JOIN User u ON u.id = r.userId
             WHERE r.eventId = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelEvent (ID, trip, name, description, start, end, hastickets, ticket, ticketUrl, url, image, organizer, address, latitude, longitude, OSMID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['trip'] ?? null,
            $data['name'],
            $data['description'] ?? null,
            $data['start'],
            $data['end'],
            $data['hastickets'] ?? '0',
            $data['ticket'] ?? null,
            $data['ticketUrl'] ?? null,
            $data['url'] ?? null,
            $data['image'] ?? null,
            $data['organizer'] ?? null,
            $data['address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['OSMID'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['trip', 'name', 'description', 'start', 'end', 'hastickets', 'ticket', 'ticketUrl', 'url', 'image', 'organizer', 'address', 'latitude', 'longitude', 'OSMID'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`$field` = ?";
                $values[] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE TravelEvent SET ' . implode(', ', $sets) . ' WHERE ID = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelEvent WHERE ID = ?');
        $stmt->execute([$id]);
    }
}
