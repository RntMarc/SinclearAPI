<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PollFormService;
use Sinclear\Api\Services\PollService;

final readonly class PollController
{
    private const array ERROR_MAP = [
        'not_found' => ['error' => 'not_found', 'status' => 404],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'title_required' => ['error' => 'title_required', 'status' => 400],
        'invalid_type' => ['error' => 'invalid_type', 'status' => 400],
        'invalid_config' => ['error' => 'invalid_config', 'status' => 400],
        'invalid_answer' => ['error' => 'invalid_answer', 'status' => 400],
        'answer_required' => ['error' => 'answer_required', 'status' => 400],
        'invalid_status' => ['error' => 'invalid_status', 'status' => 400],
        'invalid_access_mode' => ['error' => 'invalid_access_mode', 'status' => 400],
        'invalid_submission_mode' => ['error' => 'invalid_submission_mode', 'status' => 400],
        'invalid_visibility' => ['error' => 'invalid_visibility', 'status' => 400],
        'invalid_option' => ['error' => 'invalid_option', 'status' => 400],
        'invalid_target' => ['error' => 'invalid_target', 'status' => 400],
        'option_not_found' => ['error' => 'option_not_found', 'status' => 404],
        'time_forbidden' => ['error' => 'time_forbidden', 'status' => 400],
        'date_forbidden' => ['error' => 'date_forbidden', 'status' => 400],
        'poll_closed' => ['error' => 'poll_closed', 'status' => 409],
        'already_responded' => ['error' => 'already_responded', 'status' => 409],
        'results_hidden' => ['error' => 'results_hidden', 'status' => 403],
    ];

    public function __construct(
        private PollService $pollService,
        private PollFormService $formService,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);

        try {
            $poll = $this->pollService->create($user, $body);
            return ResponseFactory::json(['data' => $poll], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        try {
            $result = $this->pollService->list($user, $params, $page, $limit);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            return ResponseFactory::json(['data' => $this->pollService->get($user, $args['id'])], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            return ResponseFactory::json(
                ['data' => $this->pollService->update($user, $args['id'], $this->body($request))],
                200,
                $response,
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->pollService->delete($user, $args['id']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function close(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            return ResponseFactory::json(['data' => $this->pollService->close($user, $args['id'])], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listInvites(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            return ResponseFactory::json(['data' => $this->pollService->listInvites($user, $args['id'])], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function addInvites(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $userIds = $body['userIds'] ?? null;
        if (!is_array($userIds)) {
            $userIds = isset($body['userId']) ? [$body['userId']] : [];
        }

        try {
            return ResponseFactory::json(
                ['data' => $this->pollService->addInvites($user, $args['id'], $userIds)],
                201,
                $response,
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function removeInvite(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->pollService->removeInvite($user, $args['id'], $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listResponses(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $result = $this->formService->listResponses($user, $args['id']);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function submitResponse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];

        try {
            $result = $this->formService->submit($user, $args['id'], $answers);
            return ResponseFactory::json(['data' => $result], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function getMyResponse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $result = $this->formService->getMine($user, $args['id']);
            return ResponseFactory::json(['data' => $result], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updateResponse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];

        try {
            $result = $this->formService->updateResponse($user, $args['id'], $args['responseId'], $answers);
            return ResponseFactory::json(['data' => $result], 200, $response);
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
