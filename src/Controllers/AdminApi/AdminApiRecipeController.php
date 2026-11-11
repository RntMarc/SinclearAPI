<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Services\AdminAuditService;

final readonly class AdminApiRecipeController
{
    use AdminApiConcern;

    public function __construct(
        private RecipeRepository $recipeRepo,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $data = array_map(static fn(array $r) => [
            'id' => $r['id'],
            'title' => $r['title'],
            'category' => $r['category'],
            'creatorDisplayName' => $r['creatorDisplayName'] ?? null,
            'avgRating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
            'ratingCount' => (int) ($r['rating_count'] ?? 0),
            'isDraft' => (int) ($r['isDraft'] ?? 0) === 1,
            'createdAt' => $r['createdAt'],
        ], $this->recipeRepo->listAll());

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $recipe = $this->recipeRepo->findById($id);
        if ($recipe === null) {
            return ResponseFactory::json(['error' => 'recipe_not_found'], 404, $response);
        }

        $this->recipeRepo->delete($id);
        $this->auditService->record($user, 'delete', 'recipe', $id, $request);

        return ResponseFactory::noContent($response);
    }
}
