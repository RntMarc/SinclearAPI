<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class RecipeIngredientRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function listByRecipe(string $recipeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM RecipeIngredient WHERE recipeId = ? ORDER BY `order` ASC');
        $stmt->execute([$recipeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function replaceByRecipe(string $recipeId, array $ingredients): void
    {
        $this->pdo->prepare('DELETE FROM RecipeIngredient WHERE recipeId = ?')->execute([$recipeId]);

        if (empty($ingredients)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO RecipeIngredient (id, recipeId, amount, unit, name, `order`)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($ingredients as $idx => $ing) {
            $id = $ing['id'] ?? \Ramsey\Uuid\Uuid::uuid7()->toString();
            $stmt->execute([
                $id,
                $recipeId,
                $ing['amount'],
                $ing['unit'],
                $ing['name'],
                $ing['order'] ?? $idx,
            ]);
        }
    }
}
