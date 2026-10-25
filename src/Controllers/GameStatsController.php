<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\GameAccountPolicy;
use Sinclear\Api\Services\GameStatsService;

final readonly class GameStatsController
{
    public function __construct(
        private GameStatsService $service,
        private GameAccountPolicy $policy,
    ) {}

    /** @param array<string, string> $args */
    public function single(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $game = (string) $args['game'];
        $userId = (string) $args['userId'];

        if (!$this->policy->canView($user, $userId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $stats = $this->service->getStats($game, $userId);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        if ($stats === null) {
            return ResponseFactory::json(['error' => 'game_account_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $stats], 200, $response);
    }

    /** @param array<string, string> $args */
    public function batch(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // Batch-Statistiken sind oeffentlich; die JWT-Pruefung erfolgt bereits
        // ueber die AuthenticationMiddleware.
        $this->requireUser($request);
        $game = (string) $args['game'];

        $params = $request->getQueryParams();
        $userIds = $this->parseUserIds($params['userIds'] ?? null);

        if ($userIds === []) {
            return ResponseFactory::json(['error' => 'missing_user_ids'], 400, $response);
        }

        try {
            $data = $this->service->getBatch($game, $userIds);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    /** @param array<string, string> $args */
    public function cacheMeta(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireUser($request);

        try {
            $meta = $this->service->getCacheMeta((string) $args['game']);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json($meta, 200, $response);
    }

    /**
     * @return list<string>
     */
    private function parseUserIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_scalar($id)) {
                continue;
            }

            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function errorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'unknown_game' => 404,
            'too_many_user_ids' => 400,
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
