<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DiscoverPlaceSubmissionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM DiscoverPlaceSubmission WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DiscoverPlaceSubmission (id, userId, name, address, latitude, longitude,
                                                  photo, mapLink, website, rating, comment, note,
                                                  status, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['name'],
            $data['address'] ?? null,
            $data['latitude'],
            $data['longitude'],
            $data['photo'] ?? null,
            $data['mapLink'] ?? null,
            $data['website'] ?? null,
            $data['rating'],
            $data['comment'] ?? null,
            $data['note'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $fields = [];
        $params = [];

        foreach (['name', 'address', 'latitude', 'longitude', 'photo', 'mapLink', 'website', 'rating', 'comment', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return;
        }

        $params[] = $id;
        $fields[] = 'updatedAt = NOW(3)';
        $stmt = $this->pdo->prepare(
            'UPDATE DiscoverPlaceSubmission SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }

    public function findByUserId(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM DiscoverPlaceSubmission WHERE userId = ?');
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $stmt = $this->pdo->prepare(
            'SELECT * FROM DiscoverPlaceSubmission WHERE userId = ? ORDER BY createdAt DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $limit, $offset]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findAll(?string $status, int $page, int $limit): array
    {
        $conditions = '';
        $params = [];

        if ($status !== null) {
            $conditions = 'WHERE status = ?';
            $params[] = $status;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM DiscoverPlaceSubmission $conditions");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT * FROM DiscoverPlaceSubmission $conditions ORDER BY createdAt DESC LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function approve(string $id, string $adminNote, string $targetPlaceId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DiscoverPlaceSubmission
             SET status = \'transferred\', adminNote = ?, targetPlaceId = ?, updatedAt = NOW(3)
             WHERE id = ?'
        );
        $stmt->execute([$adminNote, $targetPlaceId, $id]);
    }

    public function reject(string $id, string $adminNote): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DiscoverPlaceSubmission
             SET status = \'rejected\', adminNote = ?, photo = NULL, mapLink = NULL, website = NULL,
                 rating = NULL, comment = NULL, note = NULL, updatedAt = NOW(3)
             WHERE id = ?'
        );
        $stmt->execute([$adminNote, $id]);
    }

    public function allStatusCounts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT status, COUNT(*) AS cnt FROM DiscoverPlaceSubmission GROUP BY status"
        );
        $result = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'transferred' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }
        return $result;
    }
}
