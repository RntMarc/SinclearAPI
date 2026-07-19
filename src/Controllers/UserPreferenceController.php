<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\UserPreferenceService;

final readonly class UserPreferenceController
{
    public function __construct(
        private UserPreferenceService $preferenceService,
        private LoggerInterface $logger,
    ) {}

    public function getPreferences(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $prefs = $this->preferenceService->getAll($user->id);

        return ResponseFactory::json(['data' => $prefs], 200, $response);
    }

    public function updatePreferences(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || $body === []) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        try {
            $prefs = $this->preferenceService->update($user->id, $body);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('UserPreferenceController: update failed', [
                'userId' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['data' => $prefs], 200, $response);
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
