<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\ChatService;

/**
 * Chat endpoints with real-time support.
 */
final class ChatController
{
    public function __construct(
        private readonly ChatService $chatService
    ) {
    }

    public function rooms(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        return ResponseFactory::json(['data' => $this->chatService->listRooms($user)], 200, $response);
    }

    public function messages(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $chatId = (string) ($params['chat_id'] ?? $params['chatId'] ?? '');
        $chatType = (string) ($params['chat_type'] ?? $params['chatType'] ?? 'direct');
        if ($chatId === '') {
            throw HttpException::badRequest('missing_chat_id');
        }
        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        return ResponseFactory::json(
            ['data' => $this->chatService->listMessages($user, $chatId, $chatType, $limit)],
            200,
            $response
        );
    }

    public function sendMessage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->chatService->sendMessage($user, $body);
        return ResponseFactory::json(['data' => $data], 201, $response);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $chatId = (string) ($body['chat_id'] ?? $body['chatId'] ?? '');
        $chatType = (string) ($body['chat_type'] ?? $body['chatType'] ?? 'direct');
        $this->chatService->markRead($user, $chatId, $chatType);
        return ResponseFactory::json(['success' => true], 200, $response);
    }

    public function unread(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        return ResponseFactory::json(['data' => $this->chatService->unreadCounts($user)], 200, $response);
    }

    public function directChats(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $otherUserId = (string) ($params['user_id'] ?? $params['userId'] ?? '');
        if ($otherUserId === '') {
            throw HttpException::badRequest('missing_user_id');
        }
        $chat = $this->chatService->getOrCreateDirectChat($user, $otherUserId);
        return ResponseFactory::json(['data' => $chat], 200, $response);
    }

    public function presence(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        if ($request->getMethod() === 'GET') {
            return ResponseFactory::json(['data' => ['user_id' => $user->id]], 200, $response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $status = (string) ($body['status'] ?? 'online');
        $data = $this->chatService->updatePresence($user, $status);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function sseStream(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $events = $this->chatService->fetchPendingSseEvents($user);

        $body = '';
        foreach ($events as $event) {
            $body .= 'event: ' . $event['event_type'] . "\n";
            $body .= 'data: ' . (string) $event['payload'] . "\n\n";
        }
        if ($body === '') {
            $body = ": heartbeat\n\n";
        }

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive');
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
