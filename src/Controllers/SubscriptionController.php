<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\SubscriptionService;

final readonly class SubscriptionController
{
    private const array ERROR_MAP = [
        'Subscription not found' => ['subscription_not_found', 404],
        'Forbidden' => ['forbidden', 403],
    ];

    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $adminAll = isset($params['all']) && $params['all'] === '1';

        if ($adminAll && !$user->isAdmin) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $subscriptions = $this->subscriptionService->listByUser($user->id, $adminAll);
        return ResponseFactory::json(['data' => $subscriptions], 200, $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $subscription = $this->subscriptionService->get($args['id'], $user->id);
            return ResponseFactory::json(['data' => $subscription], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getParticipants(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $participants = $this->subscriptionService->getParticipants($args['id'], $user->id);
            return ResponseFactory::json(['data' => $participants], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function errorResponse(\RuntimeException $e, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$e->getMessage()] ?? ['internal_error', 500];
        return ResponseFactory::json(['error' => $mapped[0]], $mapped[1], $response);
    }
}
