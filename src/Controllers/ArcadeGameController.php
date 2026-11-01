<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\ArcadePolicy;
use Sinclear\Api\Services\ArcadeGameService;
use Sinclear\Api\Services\ArcadeScoreService;

final readonly class ArcadeGameController
{
    public function __construct(
        private ArcadeGameService $gameService,
        private ArcadeScoreService $scoreService,
        private ArcadePolicy $policy,
    ) {}

    public function listGames(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        return ResponseFactory::json(['data' => $this->gameService->listActive()], 200, $response);
    }

    /** @param array<string, string> $args */
    public function getGame(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $game = $this->gameService->findActiveBySlug($args['slug']);
        if ($game === null) {
            return ResponseFactory::json(['error' => 'unknown_game'], 404, $response);
        }

        return ResponseFactory::json(['data' => $this->gameService->format($game)], 200, $response);
    }

    /** @param array<string, string> $args */
    public function leaderboard(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        [$page, $limit] = $this->pagination($request);

        try {
            $result = $this->scoreService->leaderboard($args['slug'], $page, $limit, $user->id);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json($result, 200, $response);
    }

    /** @param array<string, string> $args */
    public function history(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        [$page, $limit] = $this->pagination($request);

        try {
            $result = $this->scoreService->history($args['slug'], $args['userId'], $page, $limit);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json($result, 200, $response);
    }

    /** @return array{0: int, 1: int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        return [$page, $limit];
    }

    private function errorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'unknown_game', 'user_not_found', 'score_not_found' => 404,
            default => 500,
        };

        return ResponseFactory::json(['error' => $error], $status, $response);
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
