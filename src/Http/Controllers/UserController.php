<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\UserExportService;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\SubscriptionRelationRepository;

/**
 * User-specific endpoints beyond CRUD.
 */
final class UserController
{
    public function __construct(
        private readonly UserExportService $exportService,
        private readonly CloseFriendRepository $closeFriendRepository,
        private readonly SubscriptionRelationRepository $subscriptionRepository
    ) {
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        $data = $this->exportService->export($user, $args['id']);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function subscriptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $userId = (string) $args['userId'];
        if (!$user->isAdmin && $user->id !== $userId) {
            throw HttpException::forbidden();
        }

        $subs = $this->subscriptionRepository->findByUserId($userId);
        return ResponseFactory::json(['data' => $subs], 200, $response);
    }

    public function isCloseFriend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $userId = (string) $args['userId'];
        $friendId = (string) $args['friendId'];

        if (!$user->isAdmin && $user->id !== $userId) {
            throw HttpException::forbidden();
        }

        $exists = $this->closeFriendRepository->isCloseFriend($userId, $friendId);

        return ResponseFactory::json(['isCloseFriend' => $exists], 200, $response);
    }
}
