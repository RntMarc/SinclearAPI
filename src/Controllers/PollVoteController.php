<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PollVoteService;

final readonly class PollVoteController
{
    private const array ERROR_MAP = [
        'not_found' => ['error' => 'not_found', 'status' => 404],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'invalid_type' => ['error' => 'invalid_type', 'status' => 400],
        'invalid_answer' => ['error' => 'invalid_answer', 'status' => 400],
        'invalid_option' => ['error' => 'invalid_option', 'status' => 400],
        'already_voted' => ['error' => 'already_voted', 'status' => 409],
        'poll_closed' => ['error' => 'poll_closed', 'status' => 409],
        'results_hidden' => ['error' => 'results_hidden', 'status' => 403],
    ];

    public function __construct(
        private PollVoteService $voteService,
    ) {}

    public function voteStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            return ResponseFactory::json(['data' => $this->voteService->voteStatus($user, $args['id'])], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $optionIds = is_array($body['optionIds'] ?? null) ? $body['optionIds'] : [];

        try {
            $this->voteService->vote($user, $args['id'], $optionIds);
            return ResponseFactory::json(['data' => ['voted' => true]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function results(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $result = $this->voteService->results($user, $args['id']);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    private function errorResponse(string $message, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$message] ?? null;
        if ($mapped !== null) {
            return ResponseFactory::json(['error' => $mapped['error']], $mapped['status'], $response);
        }
        return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
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
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }
}
