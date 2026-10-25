<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\GameAccountPolicy;
use Sinclear\Api\Services\GameAccountService;

final readonly class GameAccountController
{
    public function __construct(
        private GameAccountService $service,
        private GameAccountPolicy $policy,
    ) {}

    public function listOwn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        if (!$this->policy->canManage($user, $user->id)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        return ResponseFactory::json(['data' => $this->service->listOwn($user->id)], 200, $response);
    }

    /** @param array<string, string> $args */
    public function link(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canManage($user, $user->id)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $body = $request->getParsedBody();
        $accountId = is_array($body) ? trim((string) ($body['accountId'] ?? '')) : '';

        if ($accountId === '') {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        try {
            $account = $this->service->link($user->id, (string) $args['game'], $accountId);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json(['data' => $account], 200, $response);
    }

    /** @param array<string, string> $args */
    public function unlink(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canManage($user, $user->id)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $deleted = $this->service->unlink($user->id, (string) $args['game']);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        if (!$deleted) {
            return ResponseFactory::json(['error' => 'game_account_not_found'], 404, $response);
        }

        return ResponseFactory::noContent($response);
    }

    /** @param array<string, string> $args */
    public function listForUser(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $targetUserId = (string) $args['userId'];

        if (!$this->policy->canView($user, $targetUserId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $accounts = $this->service->listForUser($targetUserId);
        if ($accounts === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $accounts], 200, $response);
    }

    private function errorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'unknown_game' => 404,
            'invalid_account_id', 'account_not_found' => 400,
            'game_api_unavailable' => 503,
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
