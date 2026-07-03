<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\RecipePolicy;
use Sinclear\Api\Services\RecipeService;

final readonly class RecipeController
{
    public function __construct(
        private RecipeService $recipeService,
        private RecipePolicy $policy,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $search = !empty($params['search']) ? trim($params['search']) : null;
        $sort = !empty($params['sort']) ? $params['sort'] : null;

        $validSorts = ['created_asc', 'created_desc', 'rating_asc', 'rating_desc'];
        if ($sort !== null && !in_array($sort, $validSorts, true)) {
            return ResponseFactory::json(['error' => 'invalid_sort'], 400, $response);
        }

        $result = $this->recipeService->listRecipes($page, $limit, $search, $sort);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = null;
        $authUser = $request->getAttribute(AuthenticatedUser::class);
        if ($authUser instanceof AuthenticatedUser) {
            $userId = $authUser->id;
        }

        $recipe = $this->recipeService->getRecipe($args['id'], $userId);
        if ($recipe === null) {
            return ResponseFactory::json(['error' => 'recipe_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $recipe], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (empty($body['title']) || !is_string($body['title'])) {
            return ResponseFactory::json(['error' => 'title_required'], 400, $response);
        }
        if (empty($body['category']) || !is_string($body['category'])) {
            return ResponseFactory::json(['error' => 'category_required'], 400, $response);
        }

        $validCategories = ['vorspeisen', 'hauptgerichte', 'desserts', 'salate', 'suppen', 'backen', 'fruehstueck', 'getraenke', 'sonstiges'];
        if (!in_array($body['category'], $validCategories, true)) {
            return ResponseFactory::json(['error' => 'invalid_category'], 400, $response);
        }

        $recipe = $this->recipeService->createRecipe($body, $user->id);
        return ResponseFactory::json(['data' => $recipe], 201, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $existing = $this->recipeService->getRecipe($id, $user->id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'recipe_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $existing['creatorId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        if (isset($body['category'])) {
            $validCategories = ['vorspeisen', 'hauptgerichte', 'desserts', 'salate', 'suppen', 'backen', 'fruehstueck', 'getraenke', 'sonstiges'];
            if (!in_array($body['category'], $validCategories, true)) {
                return ResponseFactory::json(['error' => 'invalid_category'], 400, $response);
            }
        }

        try {
            $this->recipeService->updateRecipe($id, $body);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $existing = $this->recipeService->getRecipe($id, $user->id);
        if ($existing === null) {
            return ResponseFactory::noContent($response);
        }

        if (!$this->policy->canModify($user, $existing['creatorId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->recipeService->deleteRecipe($id);
        return ResponseFactory::noContent($response);
    }

    public function createReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $recipeId = $args['id'];
        $body = $request->getParsedBody();

        $rating = isset($body['rating']) ? (int) $body['rating'] : 0;
        if ($rating < 1 || $rating > 5) {
            return ResponseFactory::json(['error' => 'invalid_rating'], 400, $response);
        }

        $comment = isset($body['comment']) && is_string($body['comment'])
            ? trim($body['comment'])
            : null;
        if ($comment === '') {
            $comment = null;
        }

        try {
            $review = $this->recipeService->createReview($recipeId, $user->id, $rating, $comment);
            return ResponseFactory::json(['data' => $review], 201, $response);
        } catch (\RuntimeException $e) {
            $ERROR_MAP = [
                'recipe_not_found' => 404,
                'review_exists' => 409,
            ];
            $status = $ERROR_MAP[$e->getMessage()] ?? 400;
            return ResponseFactory::json(['error' => $e->getMessage()], $status, $response);
        }
    }

    public function updateReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $reviewId = $args['reviewId'];
        $body = $request->getParsedBody();

        $review = $this->recipeService->getReview($reviewId);
        if ($review === null) {
            return ResponseFactory::json(['error' => 'review_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $review['userId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $rating = isset($body['rating']) ? (int) $body['rating'] : $review['rating'];
        $comment = array_key_exists('comment', $body)
            ? (is_string($body['comment']) ? trim($body['comment']) : null)
            : $review['comment'];

        if ($rating < 1 || $rating > 5) {
            return ResponseFactory::json(['error' => 'invalid_rating'], 400, $response);
        }

        try {
            $updated = $this->recipeService->updateReview($reviewId, $rating, $comment);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function deleteReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $reviewId = $args['reviewId'];

        $review = $this->recipeService->getReview($reviewId);
        if ($review === null) {
            return ResponseFactory::noContent($response);
        }

        if (!$this->policy->canModify($user, $review['userId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->recipeService->deleteReview($reviewId);
        return ResponseFactory::noContent($response);
    }

    public function listReviews(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $recipeId = $args['id'];
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->recipeService->listReviews($recipeId, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $bookmarked = $this->recipeService->getBookmarkStatus($user->id, $args['id']);
        return ResponseFactory::json(['data' => ['bookmarked' => $bookmarked]], 200, $response);
    }

    public function setBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        try {
            $result = $this->recipeService->setBookmark($user->id, $args['id']);
            return ResponseFactory::json(['data' => $result], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'bookmark_exists' ? 409 : 400;
            return ResponseFactory::json(['error' => $e->getMessage()], $code, $response);
        }
    }

    public function removeBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $this->recipeService->removeBookmark($user->id, $args['id']);
        return ResponseFactory::noContent($response);
    }

    public function listBookmarks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->recipeService->listBookmarks($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
