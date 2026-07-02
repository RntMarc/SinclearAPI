<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class RecipeRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Recipe WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Recipe (id, title, description, category, dietaryTags, image, creatorId, createdAt, updatedAt, servings)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3), ?)'
        );
        $stmt->execute([
            $id,
            $data['title'],
            $data['description'] ?? null,
            $data['category'],
            $data['dietaryTags'] ?? null,
            $data['image'] ?? null,
            $data['creatorId'],
            $data['servings'] ?? 4,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE Recipe
             SET title = ?, description = ?, category = ?, dietaryTags = ?, image = ?, servings = ?, updatedAt = NOW(3)
             WHERE id = ?'
        );
        $stmt->execute([
            $data['title'],
            $data['description'] ?? null,
            $data['category'],
            $data['dietaryTags'] ?? null,
            $data['image'] ?? null,
            $data['servings'] ?? 4,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM RecipeStep WHERE recipeId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM RecipeIngredient WHERE recipeId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM RecipeReview WHERE recipeId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM RecipeBookmark WHERE recipeId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM Recipe WHERE id = ?')->execute([$id]);
    }

    public function list(int $page, int $limit, ?string $search, string $sort): array
    {
        $conditions = [];
        $bindings = [];

        if ($search !== null && $search !== '') {
            $conditions[] = '(r.title LIKE ? OR i.name LIKE ?)';
            $bindings[] = '%' . $search . '%';
            $bindings[] = '%' . $search . '%';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $orderMap = [
            'created_asc' => 'r.createdAt ASC',
            'created_desc' => 'r.createdAt DESC',
            'rating_asc' => 'avg_rating ASC NULLS LAST',
            'rating_desc' => 'avg_rating DESC NULLS LAST',
        ];
        $orderBy = $orderMap[$sort] ?? 'r.createdAt DESC';

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT r.id) FROM Recipe r LEFT JOIN RecipeIngredient i ON i.recipeId = r.id $where"
        );
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT r.*, ROUND(AVG(rv.rating), 1) AS avg_rating, COUNT(DISTINCT rv.id) AS rating_count
             FROM Recipe r
             LEFT JOIN RecipeReview rv ON rv.recipeId = r.id
             LEFT JOIN RecipeIngredient i ON i.recipeId = r.id
             $where
             GROUP BY r.id
             ORDER BY $orderBy
             LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$bindings, $limit, $offset]);
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
