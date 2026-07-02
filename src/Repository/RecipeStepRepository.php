<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class RecipeStepRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function listByRecipe(string $recipeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM RecipeStep WHERE recipeId = ? ORDER BY `order` ASC');
        $stmt->execute([$recipeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function replaceByRecipe(string $recipeId, array $steps): void
    {
        $this->pdo->prepare('DELETE FROM RecipeStep WHERE recipeId = ?')->execute([$recipeId]);

        if (empty($steps)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO RecipeStep (id, recipeId, category, title, description, `order`)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($steps as $idx => $step) {
            $id = $step['id'] ?? \Ramsey\Uuid\Uuid::uuid7()->toString();
            $stmt->execute([
                $id,
                $recipeId,
                $step['category'] ?? 'sonstiges',
                $step['title'] ?? null,
                $step['description'],
                $step['order'] ?? $idx,
            ]);
        }
    }
}
