<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\RecipePolicy;
use Sinclear\Api\Services\RecipeService;

final readonly class RecipeController
{
    private const array ERROR_MAP = [
        'title_required' => ['error' => 'title_required', 'status' => 400],
        'category_required' => ['error' => 'category_required', 'status' => 400],
        'invalid_category' => ['error' => 'invalid_category', 'status' => 400],
        'invalid_unit' => ['error' => 'invalid_unit', 'status' => 400],
        'invalid_servings' => ['error' => 'invalid_servings', 'status' => 400],
        'recipe_not_found' => ['error' => 'recipe_not_found', 'status' => 404],
        'review_not_found' => ['error' => 'review_not_found', 'status' => 404],
        'review_exists' => ['error' => 'review_exists', 'status' => 409],
        'invalid_rating' => ['error' => 'invalid_rating', 'status' => 400],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'edit_window_expired' => ['error' => 'edit_window_expired', 'status' => 403],
        'bookmark_exists' => ['error' => 'bookmark_exists', 'status' => 409],
        'invalid_image' => ['error' => 'invalid_image', 'status' => 400],
        'invalid_image_encoding' => ['error' => 'invalid_image_encoding', 'status' => 400],
        'image_too_large' => ['error' => 'image_too_large', 'status' => 400],
        'invalid_image_format' => ['error' => 'invalid_image_format', 'status' => 400],
        'unsupported_image_format' => ['error' => 'unsupported_image_format', 'status' => 400],
        'image_dimensions_too_large' => ['error' => 'image_dimensions_too_large', 'status' => 400],
        'already_published' => ['error' => 'already_published', 'status' => 409],
    ];

    public function __construct(
        private RecipeService $recipeService,
        private RecipePolicy $policy,
        private Settings $settings,
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

        $authUser = $request->getAttribute(AuthenticatedUser::class);
        if (!$authUser instanceof AuthenticatedUser) {
            $result['data'] = array_map(
                fn(array $r) => $this->recipeService->sanitizeRecipePublic($r),
                $result['data'],
            );
        }

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

        if ($recipe['isDraft'] && $recipe['creatorId'] !== $userId) {
            return ResponseFactory::json(['error' => 'recipe_not_found'], 404, $response);
        }

        if (!$authUser instanceof AuthenticatedUser) {
            $recipe = $this->recipeService->sanitizeRecipePublic($recipe);
        }

        return ResponseFactory::json(['data' => $recipe], 200, $response);
    }

    public function publicHtml(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $id = trim((string) ($params['id'] ?? ''));

        if ($id === '') {
            $response->getBody()->write(
                '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Ungültige Anfrage</title></head>'
                . '<body><h1>Ungültige Anfrage</h1><p>Es fehlt der Parameter <code>id</code>.</p></body></html>'
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(400);
        }

        $userId = null;
        $authUser = $request->getAttribute(AuthenticatedUser::class);
        if ($authUser instanceof AuthenticatedUser) {
            $userId = $authUser->id;
        }

        // Gleicher Datenpfad wie GET /public/recipes/{id}: RecipeService + Sanitize
        $recipe = $this->recipeService->getRecipe($id, $userId);
        if ($recipe === null) {
            $response->getBody()->write(
                '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Rezept nicht gefunden</title></head>'
                . '<body><h1>Rezept nicht gefunden</h1></body></html>'
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(404);
        }

        if (!$authUser instanceof AuthenticatedUser) {
            $recipe = $this->recipeService->sanitizeRecipePublic($recipe);
        }

        $imageSrc = null;
        if (!empty($recipe['image']) && is_string($recipe['image'])) {
            $mime = 'image/png';
            $info = @getimagesizefromstring(base64_decode($recipe['image'], true));
            if (is_array($info) && isset($info['mime'])) {
                $mime = $info['mime'];
            }
            $imageSrc = 'data:' . $mime . ';base64,' . $recipe['image'];
        }

        $unitDisplay = [
            'g' => 'g',
            'kg' => 'kg',
            'ml' => 'ml',
            'l' => 'l',
            'tl' => 'TL',
            'el' => 'EL',
            'prise' => 'Prise',
            'stk' => 'Stk.',
            'bund' => 'Bund',
            'zehe' => 'Zehe',
            'scheibe' => 'Scheibe',
            'tasse' => 'Tasse',
            'dose' => 'Dose',
            'packung' => 'Packung',
            'tropfen' => 'Tropfen',
        ];

        $renderList = static function (array $items, callable $render): string {
            if ($items === []) {
                return '';
            }
            return implode('', array_map($render, $items));
        };

        $formatAmount = static function (float $amount): string {
            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        };

        $ingredientsHtml = $renderList($recipe['ingredients'] ?? [], static function (array $ing) use ($formatAmount, $unitDisplay): string {
            $parts = [];
            if (!empty($ing['amount']) && (float) $ing['amount'] > 0) {
                $parts[] = $formatAmount((float) $ing['amount']) . ' ';
                if (!empty($ing['unit'])) {
                    $displayUnit = $unitDisplay[$ing['unit']] ?? $ing['unit'];
                    $parts[] = htmlspecialchars($displayUnit) . ' ';
                }
            } elseif (!empty($ing['unit'])) {
                $displayUnit = $unitDisplay[$ing['unit']] ?? $ing['unit'];
                $parts[] = htmlspecialchars($displayUnit) . ' ';
            }
            $parts[] = htmlspecialchars((string) $ing['name']);
            return '<li itemprop="ingredients">' . implode('', $parts) . '</li>';
        });

        $stepsHtml = $renderList($recipe['steps'] ?? [], static function (array $step): string {
            return '<li itemprop="instructions">' . htmlspecialchars((string) $step['description']) . '</li>';
        });

        $jsonLd = [
            '@context' => 'http://schema.org',
            '@type' => 'Recipe',
            'name' => $recipe['title'],
            'description' => $recipe['description'] ?? null,
            'recipeCategory' => $recipe['category'],
            'keywords' => $recipe['dietaryTags'] ?? null,
            'recipeYield' => (string) $recipe['servings'],
            'image' => null,
            'recipeIngredient' => array_map(
                static fn(array $ing): string => trim(
                    (($ing['amount'] ?? 0) > 0
                        ? $formatAmount((float) $ing['amount'])
                        : '')
                    . (($ing['unit'] ?? '') !== '' ? ' ' . ($unitDisplay[$ing['unit']] ?? $ing['unit']) : '')
                    . ' '
                    . $ing['name']
                ),
                $recipe['ingredients'] ?? [],
            ),
            'recipeInstructions' => array_map(
                static fn(array $step): array => [
                    '@type' => 'HowToStep',
                    'text' => (string) $step['description'],
                ],
                $recipe['steps'] ?? [],
            ),
            'author' => $recipe['creatorDisplayName'] ?? null,
            'aggregateRating' => $recipe['avgRating'] !== null && $recipe['ratingCount'] !== null
                ? [
                    '@type' => 'AggregateRating',
                    'ratingValue' => round((float) $recipe['avgRating'], 2),
                    'reviewCount' => (int) $recipe['ratingCount'],
                ]
                : null,
            'datePublished' => $recipe['createdAt'] !== null ? substr($recipe['createdAt'], 0, 10) : null,
            'dateModified' => $recipe['updatedAt'] !== null ? substr($recipe['updatedAt'], 0, 10) : null,
        ];
        $jsonLd = array_filter($jsonLd, static fn(mixed $v): bool => $v !== null);
        $jsonLdHtml = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $renderConditional = static function (string $html, string $key, string $value): string {
            $open = '{{#' . $key . '}}';
            $close = '{{/' . $key . '}}';
            if ($value !== '') {
                return str_replace([$open, $close], '', $html);
            }
            $pattern = '/\{\{#' . preg_quote($key, '/') . '\}\}.*?\{\{\/' . preg_quote($key, '/') . '\}\}/s';
            return preg_replace($pattern, '', $html);
        };

        $description = $recipe['description'] !== null
            ? htmlspecialchars((string) $recipe['description'])
            : '';
        $dietaryTags = $recipe['dietaryTags'] !== null
            ? htmlspecialchars((string) $recipe['dietaryTags'])
            : '';
        $rating = $recipe['avgRating'] !== null
            ? number_format((float) $recipe['avgRating'], 1, '.', '') . ' / 5'
            : '';
        $ratingCount = $recipe['ratingCount'] !== null ? (string) $recipe['ratingCount'] : '';
        $author = ($recipe['creatorDisplayName'] ?? null) !== null ? (string) $recipe['creatorDisplayName'] : '';
        $ratingValue = $recipe['avgRating'] !== null ? (string) round((float) $recipe['avgRating'], 2) : '';
        $datePublished = $recipe['createdAt'] !== null ? substr((string) $recipe['createdAt'], 0, 10) : '';
        $dateModified = $recipe['updatedAt'] !== null ? substr((string) $recipe['updatedAt'], 0, 10) : '';

        $clientUrl = rtrim($this->settings->app['url'] ?? '', '/');
        $openUrl = $clientUrl . '/rezepte/' . rawurlencode((string) $recipe['id']);

        $html = file_get_contents(__DIR__ . '/../../templates/public-recipe.php') ?: '';
        $html = $renderConditional($html, 'description', $description);
        $html = $renderConditional($html, 'imageSrc', $imageSrc ?? '');
        $html = $renderConditional($html, 'dietaryTags', $dietaryTags);
        $html = $renderConditional($html, 'rating', $rating);
        $html = $renderConditional($html, 'author', $author);
        $html = strtr($html, [
            '{{title}}' => htmlspecialchars((string) $recipe['title']),
            '{{description}}' => $description,
            '{{imageSrc}}' => $imageSrc ?? '',
            '{{imageAlt}}' => htmlspecialchars((string) $recipe['title']),
            '{{category}}' => htmlspecialchars((string) $recipe['category']),
            '{{dietaryTags}}' => $dietaryTags,
            '{{servings}}' => (string) $recipe['servings'],
            '{{ingredients}}' => $ingredientsHtml,
            '{{steps}}' => $stepsHtml,
            '{{rating}}' => $rating,
            '{{ratingValue}}' => $ratingValue,
            '{{ratingCount}}' => $ratingCount,
            '{{author}}' => htmlspecialchars($author),
            '{{datePublished}}' => $datePublished,
            '{{dateModified}}' => $dateModified,
            '{{jsonLd}}' => $jsonLdHtml,
            '{{openUrl}}' => htmlspecialchars($openUrl),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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

        try {
            $recipe = $this->recipeService->createRecipe($body, $user->id);
            return ResponseFactory::json(['data' => $recipe], 201, $response);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
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
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
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

        if ($existing['isDraft'] && $existing['creatorId'] !== $user->id && !$user->isAdmin) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        if (!$this->policy->canDelete($user, $existing['creatorId'], $existing['createdAt'], $existing['isDraft'])) {
            if ($user->id !== $existing['creatorId']) {
                return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
            }
            return ResponseFactory::json(['error' => 'edit_window_expired'], 403, $response);
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

        $authUser = $request->getAttribute(AuthenticatedUser::class);
        if (!$authUser instanceof AuthenticatedUser) {
            $result['data'] = array_map(
                fn(array $r) => $this->recipeService->sanitizeReviewPublic($r),
                $result['data'],
            );
        }

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

    public function listDrafts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->recipeService->listDrafts($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function publish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $existing = $this->recipeService->getRecipe($id, $user->id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'recipe_not_found'], 404, $response);
        }

        if (!$this->policy->canPublish($user, $existing['creatorId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $recipe = $this->recipeService->publishRecipe($id, $user->id);
            return ResponseFactory::json(['data' => $recipe], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    private function errorResponse(string $message, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$message] ?? null;
        if ($mapped !== null) {
            return ResponseFactory::json(['error' => $mapped['error']], $mapped['status'], $response);
        }
        return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
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
