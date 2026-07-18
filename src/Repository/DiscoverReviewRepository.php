<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DiscoverReviewRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function hasReviews(string $placeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM DiscoverReview WHERE placeId = ?');
        $stmt->execute([$placeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM DiscoverReview WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByPlace(string $placeId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM DiscoverReview WHERE placeId = ?');
        $countStmt->execute([$placeId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT r.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM DiscoverReview r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.placeId = ?
             ORDER BY r.createdAt DESC LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$placeId, $limit, $offset]);
        $reviews = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $reviews,
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
            'INSERT INTO DiscoverReview (id, placeId, userId, rating, comment, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $id,
            $data['placeId'],
            $data['userId'],
            $data['rating'],
            $data['comment'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DiscoverReview SET rating = ?, comment = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['rating'],
            $data['comment'] ?? null,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM DiscoverReview WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getAvgRating(string $placeId): ?float
    {
        $stmt = $this->pdo->prepare('SELECT ROUND(AVG(rating), 1) FROM DiscoverReview WHERE placeId = ?');
        $stmt->execute([$placeId]);
        $avg = $stmt->fetchColumn();
        return $avg !== false && $avg !== null ? (float) $avg : null;
    }
}
