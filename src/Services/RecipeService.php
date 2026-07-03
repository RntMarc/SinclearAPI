<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Repository\RecipeIngredientRepository;
use Sinclear\Api\Repository\RecipeStepRepository;
use Sinclear\Api\Repository\RecipeReviewRepository;
use Sinclear\Api\Repository\RecipeBookmarkRepository;

final readonly class RecipeService
{
    public function __construct(
        private RecipeRepository $recipeRepo,
        private RecipeIngredientRepository $ingredientRepo,
        private RecipeStepRepository $stepRepo,
        private RecipeReviewRepository $reviewRepo,
        private RecipeBookmarkRepository $bookmarkRepo,
    ) {}

    public function listRecipes(int $page, int $limit, ?string $search, string $sort): array
    {
        $result = $this->recipeRepo->list($page, $limit, $search, $sort);
        $result['data'] = array_map(fn(array $r) => $this->formatRecipe($r), $result['data']);
        return $result;
    }

    public function getRecipe(string $id, ?string $currentUserId): ?array
    {
        $recipe = $this->recipeRepo->findById($id);
        if ($recipe === null) {
            return null;
        }
        return $this->formatRecipeDetail($recipe, $currentUserId);
    }

    public function createRecipe(array $data, string $creatorId): array
    {
        $id = $this->recipeRepo->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'dietaryTags' => $data['dietaryTags'] ?? null,
            'image' => $data['image'] ?? null,
            'creatorId' => $creatorId,
            'servings' => $data['servings'] ?? 4,
        ]);

        if (!empty($data['ingredients'])) {
            $this->ingredientRepo->replaceByRecipe($id, $data['ingredients']);
        }

        if (!empty($data['steps'])) {
            $this->stepRepo->replaceByRecipe($id, $data['steps']);
        }

        $recipe = $this->recipeRepo->findById($id);
        return $this->formatRecipeDetail($recipe, $creatorId);
    }

    public function updateRecipe(string $id, array $data): void
    {
        $recipe = $this->recipeRepo->findById($id);
        if ($recipe === null) {
            throw new \RuntimeException('recipe_not_found');
        }

        $this->recipeRepo->update($id, [
            'title' => $data['title'] ?? $recipe['title'],
            'description' => array_key_exists('description', $data) ? $data['description'] : $recipe['description'],
            'category' => $data['category'] ?? $recipe['category'],
            'dietaryTags' => array_key_exists('dietaryTags', $data) ? $data['dietaryTags'] : $recipe['dietaryTags'],
            'image' => array_key_exists('image', $data) ? $data['image'] : $recipe['image'],
            'servings' => $data['servings'] ?? $recipe['servings'],
        ]);

        if (array_key_exists('ingredients', $data)) {
            $this->ingredientRepo->replaceByRecipe($id, $data['ingredients']);
        }

        if (array_key_exists('steps', $data)) {
            $this->stepRepo->replaceByRecipe($id, $data['steps']);
        }
    }

    public function deleteRecipe(string $id): void
    {
        $this->recipeRepo->delete($id);
    }

    public function createReview(string $recipeId, string $userId, int $rating, ?string $comment): array
    {
        $recipe = $this->recipeRepo->findById($recipeId);
        if ($recipe === null) {
            throw new \RuntimeException('recipe_not_found');
        }

        $existing = $this->reviewRepo->findByUserAndRecipe($recipeId, $userId);
        if ($existing !== null) {
            throw new \RuntimeException('review_exists');
        }

        $id = $this->reviewRepo->create([
            'recipeId' => $recipeId,
            'userId' => $userId,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $review = $this->reviewRepo->findById($id);
        return $this->formatReview($review);
    }

    public function getReview(string $id): ?array
    {
        $review = $this->reviewRepo->findById($id);
        if ($review === null) {
            return null;
        }
        return $this->formatReview($review);
    }

    public function updateReview(string $reviewId, int $rating, ?string $comment): array
    {
        $review = $this->reviewRepo->findById($reviewId);
        if ($review === null) {
            throw new \RuntimeException('review_not_found');
        }

        $this->reviewRepo->update($reviewId, [
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $review = $this->reviewRepo->findById($reviewId);
        return $this->formatReview($review);
    }

    public function deleteReview(string $reviewId): void
    {
        $this->reviewRepo->delete($reviewId);
    }

    public function listReviews(string $recipeId, int $page, int $limit): array
    {
        $result = $this->reviewRepo->listByRecipe($recipeId, $page, $limit);
        $result['data'] = array_map(fn(array $r) => $this->formatReview($r), $result['data']);
        return $result;
    }

    public function getBookmarkStatus(string $userId, string $recipeId): bool
    {
        return $this->bookmarkRepo->isBookmarked($userId, $recipeId);
    }

    public function setBookmark(string $userId, string $recipeId): array
    {
        $existing = $this->bookmarkRepo->find($userId, $recipeId);
        if ($existing !== null) {
            throw new \RuntimeException('bookmark_exists');
        }

        $this->bookmarkRepo->create($userId, $recipeId);
        return ['bookmarked' => true];
    }

    public function removeBookmark(string $userId, string $recipeId): void
    {
        $this->bookmarkRepo->delete($userId, $recipeId);
    }

    public function listBookmarks(string $userId, int $page, int $limit): array
    {
        $result = $this->bookmarkRepo->listByUser($userId, $page, $limit);
        $result['data'] = array_map(fn(array $r) => $this->formatRecipe($r), $result['data']);
        return $result;
    }

    private function formatRecipe(array $recipe): array
    {
        return [
            'id' => $recipe['id'],
            'title' => $recipe['title'],
            'description' => $recipe['description'],
            'category' => $recipe['category'],
            'dietaryTags' => $recipe['dietaryTags'],
            'image' => $recipe['image'],
            'servings' => (int) $recipe['servings'],
            'creatorId' => $recipe['creatorId'],
            'createdAt' => $recipe['createdAt'],
            'updatedAt' => $recipe['updatedAt'],
            'avgRating' => isset($recipe['avg_rating']) ? (float) $recipe['avg_rating'] : null,
            'ratingCount' => isset($recipe['rating_count']) ? (int) $recipe['rating_count'] : null,
        ];
    }

    private function formatRecipeDetail(array $recipe, ?string $currentUserId): array
    {
        $data = $this->formatRecipe($recipe);
        $data['ingredients'] = array_map(
            fn(array $i) => $this->formatIngredient($i),
            $this->ingredientRepo->listByRecipe($recipe['id'])
        );
        $data['steps'] = array_map(
            fn(array $s) => $this->formatStep($s),
            $this->stepRepo->listByRecipe($recipe['id'])
        );

        $data['avgRating'] = $this->reviewRepo->getAvgRating($recipe['id']);
        $data['ratingCount'] = $this->reviewRepo->getRatingCount($recipe['id']);

        $data['isBookmarked'] = $currentUserId !== null
            ? $this->bookmarkRepo->isBookmarked($currentUserId, $recipe['id'])
            : false;

        return $data;
    }

    private function formatIngredient(array $ing): array
    {
        return [
            'id' => $ing['id'],
            'amount' => (float) $ing['amount'],
            'unit' => $ing['unit'],
            'name' => $ing['name'],
            'order' => (int) $ing['order'],
        ];
    }

    private function formatStep(array $step): array
    {
        return [
            'id' => $step['id'],
            'category' => $step['category'],
            'title' => $step['title'],
            'description' => $step['description'],
            'order' => (int) $step['order'],
        ];
    }

    private function formatReview(array $review): array
    {
        return [
            'id' => $review['id'],
            'recipeId' => $review['recipeId'],
            'userId' => $review['userId'],
            'rating' => (int) $review['rating'],
            'comment' => $review['comment'],
            'createdAt' => $review['createdAt'],
        ];
    }
}
