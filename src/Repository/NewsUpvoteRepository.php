<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class NewsUpvoteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function find(string $userId, string $articleId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM NewsUpvote WHERE userId = ? AND articleId = ?'
        );
        $stmt->execute([$userId, $articleId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $userId, string $articleId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO NewsUpvote (id, articleId, userId, createdAt) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $articleId, $userId]);
        return $id;
    }

    public function delete(string $userId, string $articleId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM NewsUpvote WHERE userId = ? AND articleId = ?'
        );
        $stmt->execute([$userId, $articleId]);
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM NewsUpvote WHERE userId = ?'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT a.*, u.createdAt AS votedAt
             FROM NewsUpvote u
             INNER JOIN NewsArticle a ON a.id = u.articleId
             WHERE u.userId = ?
             ORDER BY u.createdAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$userId, $limit, $offset]);

        return [
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
