<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class RecipeBookmarkRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function find(string $userId, string $recipeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM RecipeBookmark WHERE userId = ? AND recipeId = ?'
        );
        $stmt->execute([$userId, $recipeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function isBookmarked(string $userId, string $recipeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM RecipeBookmark WHERE userId = ? AND recipeId = ?'
        );
        $stmt->execute([$userId, $recipeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $userId, string $recipeId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RecipeBookmark (id, userId, recipeId, createdAt) VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $userId, $recipeId]);
        return $id;
    }

    public function delete(string $userId, string $recipeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM RecipeBookmark WHERE userId = ? AND recipeId = ?'
        );
        $stmt->execute([$userId, $recipeId]);
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM RecipeBookmark WHERE userId = ?'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT r.*, b.createdAt AS bookmarkedAt
             FROM RecipeBookmark b
             JOIN Recipe r ON r.id = b.recipeId
             WHERE b.userId = ?
             ORDER BY b.createdAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$userId, $limit, $offset]);
        $recipes = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $recipes,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
