<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class RecipeReviewRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM RecipeReview WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByRecipe(string $recipeId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM RecipeReview WHERE recipeId = ?');
        $countStmt->execute([$recipeId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT * FROM RecipeReview WHERE recipeId = ? ORDER BY createdAt DESC LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$recipeId, $limit, $offset]);
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

    public function findByUserAndRecipe(string $recipeId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM RecipeReview WHERE recipeId = ? AND userId = ?'
        );
        $stmt->execute([$recipeId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RecipeReview (id, recipeId, userId, rating, comment, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['recipeId'],
            $data['userId'],
            $data['rating'],
            $data['comment'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE RecipeReview SET rating = ?, comment = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['rating'],
            $data['comment'] ?? null,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM RecipeReview WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getAvgRating(string $recipeId): ?float
    {
        $stmt = $this->pdo->prepare('SELECT ROUND(AVG(rating), 1) FROM RecipeReview WHERE recipeId = ?');
        $stmt->execute([$recipeId]);
        $avg = $stmt->fetchColumn();
        return $avg !== false && $avg !== null ? (float) $avg : null;
    }

    public function getRatingCount(string $recipeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM RecipeReview WHERE recipeId = ?');
        $stmt->execute([$recipeId]);
        return (int) $stmt->fetchColumn();
    }
}
