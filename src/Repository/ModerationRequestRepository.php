<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ModerationRequestRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM ModerationRequest r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ModerationRequest
                (id, userId, requestType, objectType, objectId, message, status, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['requestType'],
            $data['objectType'],
            $data['objectId'],
            $data['message'],
            $data['status'] ?? 'unread',
        ]);
        return $id;
    }

    public function update(string $id, string $status, ?string $adminComment): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ModerationRequest
             SET status = ?, adminComment = ?, updatedAt = NOW(3)
             WHERE id = ?'
        );
        $stmt->execute([$status, $adminComment, $id]);
    }

    public function list(
        int $page,
        int $limit,
        ?string $status = null,
        ?string $objectType = null,
        ?string $requestType = null,
    ): array {
        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($objectType !== null) {
            $conditions[] = 'r.objectType = ?';
            $params[] = $objectType;
        }
        if ($requestType !== null) {
            $conditions[] = 'r.requestType = ?';
            $params[] = $requestType;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ModerationRequest r' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = 'SELECT r.*, u.displayName AS userDisplayName, u.image AS userImage
                FROM ModerationRequest r
                LEFT JOIN User u ON u.id = r.userId'
            . $where
            . ' ORDER BY r.createdAt DESC LIMIT ? OFFSET ?';

        $dataStmt = $this->pdo->prepare($sql);
        $dataStmt->execute([...$params, $limit, $offset]);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ModerationRequest WHERE userId = ?');
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM ModerationRequest r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.userId = ?
             ORDER BY r.createdAt DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function getStatusCounts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) AS count FROM ModerationRequest GROUP BY status'
        );
        $counts = array_fill_keys(
            ['unread', 'read', 'in_work', 'external_contact', 'public_decision', 'accepted', 'denied', 'postponed'],
            0,
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['count'];
            }
        }
        return $counts;
    }
}
