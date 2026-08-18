<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\DirectMessagePolicy;
use Sinclear\Api\Services\DirectMessageService;

final readonly class ChatController
{
    private const array ERROR_MAP = [
        'conversation_not_found' => ['error' => 'conversation_not_found', 'status' => 404],
        'message_not_found' => ['error' => 'message_not_found', 'status' => 404],
        'user_not_found' => ['error' => 'user_not_found', 'status' => 404],
        'cannot_chat_self' => ['error' => 'cannot_chat_self', 'status' => 400],
        'content_required' => ['error' => 'content_required', 'status' => 400],
        'content_too_long' => ['error' => 'content_too_long', 'status' => 400],
        'invalid_type' => ['error' => 'invalid_type', 'status' => 400],
        'invalid_payload' => ['error' => 'invalid_payload', 'status' => 400],
        'edit_window_expired' => ['error' => 'edit_window_expired', 'status' => 400],
        'message_deleted' => ['error' => 'message_deleted', 'status' => 400],
        'rate_limit_exceeded' => ['error' => 'rate_limit_exceeded', 'status' => 429],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
    ];

    public function __construct(
        private DirectMessageService $service,
        private DirectMessagePolicy $policy,
    ) {}

    public function listConversations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->service->listConversations($user->id, $page, $limit);

        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function openConversation(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $otherUserId = trim((string) ($body['userId'] ?? ''));
        if ($otherUserId === '') {
            return ResponseFactory::json(['error' => 'user_not_found'], 400, $response);
        }

        try {
            $result = $this->service->openConversation($user->id, $otherUserId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        $status = $result['created'] ? 201 : 200;
        return ResponseFactory::json(['data' => $result['conversation']], $status, $response);
    }

    public function getConversation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $conversationId = $args['id'];

        if (!$this->policy->canAccess($user, $conversationId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $result = $this->service->getConversation($user->id, $conversationId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json(['data' => $result], 200, $response);
    }

    public function listMessages(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $conversationId = $args['id'];
        $params = $request->getQueryParams();

        if (!$this->policy->canAccess($user, $conversationId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $beforeSeq = isset($params['before']) ? max(0, (int) $params['before']) : null;
        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));

        try {
            $result = $this->service->getMessages($user->id, $conversationId, $beforeSeq, $limit);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json($result, 200, $response);
    }

    public function sendMessage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $conversationId = $args['id'];
        $body = $request->getParsedBody();

        if (!$this->policy->canAccess($user, $conversationId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $result = $this->service->sendMessage($user->id, $conversationId, $body);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json(['data' => $result], 201, $response);
    }

    public function editMessage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $messageId = $args['id'];
        $body = $request->getParsedBody();

        $newContent = trim((string) ($body['content'] ?? ''));
        if ($newContent === '') {
            return ResponseFactory::json(['error' => 'content_required'], 400, $response);
        }

        try {
            $result = $this->service->editMessage($user->id, $messageId, $newContent);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json(['data' => $result], 200, $response);
    }

    public function deleteMessage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $messageId = $args['id'];

        try {
            $this->service->deleteMessage($user->id, $messageId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::noContent($response);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $conversationId = $args['id'];
        $body = $request->getParsedBody();

        if (!$this->policy->canAccess($user, $conversationId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $seq = (int) ($body['seq'] ?? 0);

        $this->service->markRead($user->id, $conversationId, $seq);

        return ResponseFactory::noContent($response);
    }

    public function setTyping(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $conversationId = $args['id'];
        $body = $request->getParsedBody();

        if (!$this->policy->canAccess($user, $conversationId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $typing = (bool) ($body['typing'] ?? false);

        $this->service->setTyping($user->id, $conversationId, $typing);

        return ResponseFactory::noContent($response);
    }

    public function sync(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $afterSeq = max(0, (int) ($params['after'] ?? 0));
        $limit = min(500, max(1, (int) ($params['limit'] ?? 200)));

        $result = $this->service->sync($user->id, $afterSeq, $limit);

        return ResponseFactory::json($result, 200, $response);
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
}
