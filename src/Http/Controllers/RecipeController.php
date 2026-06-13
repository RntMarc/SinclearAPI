<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class RecipeController
{
    public function __construct(
        private readonly \PDO $pdo
    ) {
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }

    /**
     * GET /recipes/list
     * List all recipes with review stats and bookmark status.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $sql = "SELECT r.id, r.title, r.description, r.category, r.dietaryTags, r.image,
                       r.creatorId, u.displayName AS creatorName, r.createdAt,
                       AVG(rr.rating) AS avgRating,
                       COUNT(rr.id) AS reviewCount,
                       EXISTS (SELECT 1 FROM RecipeBookmark rb WHERE rb.recipeId = r.id AND rb.userId = ?) AS isBookmarked
                FROM Recipe r
                LEFT JOIN `User` u ON u.id = r.creatorId
                LEFT JOIN RecipeReview rr ON rr.recipeId = r.id
                GROUP BY r.id, u.displayName
                ORDER BY r.createdAt DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$user->id]);
        $recipes = $stmt->fetchAll();

        $recipes = array_map(function ($r) {
            $r['avgRating'] = $r['avgRating'] !== null ? round((float) $r['avgRating'], 1) : null;
            $r['reviewCount'] = (int) $r['reviewCount'];
            $r['isBookmarked'] = (bool) $r['isBookmarked'];
            return $r;
        }, $recipes);

        return ResponseFactory::json(['data' => $recipes], 200, $response);
    }

    /**
     * GET /recipes/{id}/detail
     * Single recipe with ingredients, steps, reviews, and bookmark status.
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $recipeId = $args['id'];

        // Get recipe with stats
        $sql = "SELECT r.*, u.displayName AS creatorName, u.image AS creatorImage,
                       AVG(rr.rating) AS avgRating,
                       COUNT(rr.id) AS reviewCount
                FROM Recipe r
                LEFT JOIN `User` u ON u.id = r.creatorId
                LEFT JOIN RecipeReview rr ON rr.recipeId = r.id
                WHERE r.id = ?
                GROUP BY r.id, u.displayName, u.image";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch();
        if (!$recipe) {
            throw HttpException::notFound();
        }
        $recipe['avgRating'] = $recipe['avgRating'] !== null ? round((float) $recipe['avgRating'], 1) : null;
        $recipe['reviewCount'] = (int) $recipe['reviewCount'];

        // Get ingredients
        $ingStmt = $this->pdo->prepare(
            "SELECT * FROM RecipeIngredient WHERE recipeId = ? ORDER BY `order`"
        );
        $ingStmt->execute([$recipeId]);
        $ingredients = $ingStmt->fetchAll();

        // Get steps
        $stepStmt = $this->pdo->prepare(
            "SELECT * FROM RecipeStep WHERE recipeId = ? ORDER BY `order`"
        );
        $stepStmt->execute([$recipeId]);
        $steps = $stepStmt->fetchAll();

        // Get reviews
        $revStmt = $this->pdo->prepare(
            "SELECT rr.id, rr.rating, rr.comment, rr.createdAt, rr.userId,
                    u.displayName, u.image
             FROM RecipeReview rr
             INNER JOIN `User` u ON u.id = rr.userId
             WHERE rr.recipeId = ?
             ORDER BY rr.createdAt DESC"
        );
        $revStmt->execute([$recipeId]);
        $reviews = $revStmt->fetchAll();

        // Check bookmark
        $bmStmt = $this->pdo->prepare(
            "SELECT id FROM RecipeBookmark WHERE recipeId = ? AND userId = ? LIMIT 1"
        );
        $bmStmt->execute([$recipeId, $user->id]);
        $bookmark = $bmStmt->fetch();

        return ResponseFactory::json([
            'data' => [
                ...$recipe,
                'ingredients' => $ingredients,
                'steps' => $steps,
                'reviews' => $reviews,
                'isBookmarked' => (bool) $bookmark,
            ],
        ], 200, $response);
    }
}
