<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Middleware\ArcadeSessionMiddleware;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\ArcadePolicy;
use Sinclear\Api\Services\ArcadeGameService;
use Sinclear\Api\Services\ArcadeSessionService;

final readonly class ArcadeSessionController
{
    public function __construct(
        private ArcadeGameService $gameService,
        private ArcadeSessionService $sessionService,
        private ArcadePolicy $policy,
    ) {}

    /** @param array<string, string> $args */
    public function create(
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

        $session = $this->sessionService->createSession($user->id, (string) $game['id']);

        return ResponseFactory::json([
            'token' => $session['token'],
            'expiresAt' => $session['expiresAt'],
            'playUrl' => $this->gameService->buildPlayUrl((string) $game['slug'], $session['token']),
        ], 201, $response);
    }

    /** @param array<string, string> $args */
    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $game = $this->gameService->findBySlug($args['slug']);
        if ($game === null) {
            return ResponseFactory::json(['error' => 'unknown_game'], 404, $response);
        }

        $this->sessionService->revokeAll($user->id, (string) $game['id']);

        return ResponseFactory::noContent($response);
    }

    public function renew(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requireSession($request);

        $game = $this->gameService->findById((string) $session['gameId']);
        if ($game === null) {
            return ResponseFactory::json(['error' => 'unknown_game'], 404, $response);
        }

        $renewed = $this->sessionService->renew($session);

        return ResponseFactory::json([
            'token' => $renewed['token'],
            'expiresAt' => $renewed['expiresAt'],
            'playUrl' => $this->gameService->buildPlayUrl((string) $game['slug'], $renewed['token']),
        ], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function requireSession(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(ArcadeSessionMiddleware::ATTRIBUTE);
        if (!is_array($session)) {
            throw new \RuntimeException('Arcade session required');
        }

        return $session;
    }
}
