<?php

namespace Sinclear\Api\Controllers\AdminApi;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\ArcadeGameService;

final readonly class AdminApiArcadeController
{
    use AdminApiConcern;

    public function __construct(
        private ArcadeGameService $arcadeGameService,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        return ResponseFactory::json(
            ['data' => $this->arcadeGameService->listAllForAdmin()],
            200,
            $response,
        );
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $game = $this->arcadeGameService->create($this->parsedBody($request));
        } catch (InvalidArgumentException $e) {
            return $this->arcadeErrorResponse($e->getMessage(), $response);
        }

        $this->auditService->record($user, 'create', 'arcade_game', $game['id'], $request);

        return ResponseFactory::json(['data' => $game], 201, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $game = $this->arcadeGameService->update($args['id'], $this->parsedBody($request));
        } catch (InvalidArgumentException $e) {
            return $this->arcadeErrorResponse($e->getMessage(), $response);
        }

        $this->auditService->record($user, 'update', 'arcade_game', $args['id'], $request);

        return ResponseFactory::json(['data' => $game], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->arcadeGameService->delete($args['id'])) {
            return ResponseFactory::json(['error' => 'game_not_found'], 404, $response);
        }

        $this->auditService->record($user, 'delete', 'arcade_game', $args['id'], $request);

        return ResponseFactory::noContent($response);
    }

    private function arcadeErrorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'game_not_found' => 404,
            'slug_required',
            'invalid_slug',
            'slug_taken',
            'title_required',
            'title_too_long',
            'thumbnail_url_too_long',
            'invalid_score_direction',
            'invalid_is_active',
            'invalid_sort_order',
            'no_fields_to_update' => 400,
            default => 500,
        };

        return ResponseFactory::json(['error' => $error], $status, $response);
    }
}
