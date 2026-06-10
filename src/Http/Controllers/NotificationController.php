<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\NotificationService;

/**
 * Notification badge endpoint.
 */
final class NotificationController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function badges(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        return ResponseFactory::json(
            ['data' => $this->notificationService->badges($user)],
            200,
            $response
        );
    }

    public function readByType(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $types = $body['type'] ?? [];

        if (!is_array($types)) {
            throw HttpException::badRequest('invalid_types');
        }

        $this->notificationService->readByType($user, array_map('strval', $types));
        return ResponseFactory::json(['success' => true], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }
}
