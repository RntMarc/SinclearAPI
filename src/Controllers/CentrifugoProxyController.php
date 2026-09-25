<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Services\DirectMessageService;
use Sinclear\Api\Services\MessageReactionService;
use Sinclear\Api\Services\RateLimiter;

/**
 * Proxy controller for Centrifugo publish/subscribe webhooks.
 *
 * Called by Centrifugo (not by clients). Secured via CentrifugoProxyMiddleware
 * (shared secret header + HTTPS). No JWT auth.
 */
final readonly class CentrifugoProxyController
{
    private const int TYPING_RATE_LIMIT = 30;
    private const int TYPING_RATE_WINDOW = 60;
    private const int REACTION_RATE_LIMIT = 60;
    private const int REACTION_RATE_WINDOW = 60;

    public function __construct(
        private ChatParticipantRepository $participantRepo,
        private RateLimiter $rateLimiter,
        private MessageReactionService $reactionService,
        private DirectMessageService $messageService,
    ) {}

    /**
     * Centrifugo subscribe proxy: validates channel access.
     *
     * Supports two channel types:
     * - `chat:<conversationId>`: validates ChatParticipant membership
     * - `user:<userId>`: validates user owns the channel (userId matches authenticated user)
     *
     * Request:  { "user": "<userId>", "channel": "chat:<conversationId>" | "user:<userId>" }
     * Response: { "result": {} } on success, 403 on failure
     */
    public function subscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        $userId = $data['user'] ?? '';
        $channel = $data['channel'] ?? '';

        if ($userId === '') {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        // User presence channel: user:<userId> — only the user themselves can subscribe
        $userChannelId = $this->parseUserChannel($channel);
        if ($userChannelId !== null) {
            if ($userId !== $userChannelId) {
                return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
            }
            return ResponseFactory::json(['result' => (object) []], 200, $response);
        }

        // Chat channel: chat:<conversationId> — validate ChatParticipant membership
        $conversationId = $this->parseConversationId($channel);
        if ($conversationId === null) {
            return ResponseFactory::json(['error' => 'invalid_channel'], 400, $response);
        }

        if (!$this->participantRepo->isParticipant($conversationId, $userId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        return ResponseFactory::json(['result' => (object) []], 200, $response);
    }

    /**
     * Centrifugo publish proxy: validates participant + rate limit and handles
     * message sends, typing events and chat reactions.
     *
     * Message request:   { "user": "<userId>", "channel": "chat:<conversationId>",
     *                      "data": { "message": { "clientId": "...", "content": "...", "replyToMessageId": "..." } } }
     * Typing request:    { "user": "<userId>", "channel": "chat:<conversationId>", "data": { "typing": true } }
     * Reaction request:  { "user": "<userId>", "channel": "chat:<conversationId>", "data": { "reaction": { "messageId": "...", "emoji": "👍", "add": true } } }
     * Message response:  { "result": { "data": { "type": "message_created", "message": { ... } } } }
     * Typing response:   { "result": { "data": { "typing": true }, "skip_history": true } }
     * Reaction response: { "result": { "data": { "type": "reaction_updated", "messageId": "...", "reactions": [ ... ] }, "skip_history": true } }
     */
    public function publish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        $userId = $data['user'] ?? '';
        $channel = $data['channel'] ?? '';
        $payload = $data['data'] ?? [];

        $conversationId = $this->parseConversationId($channel);
        if ($conversationId === null) {
            return ResponseFactory::json(['error' => 'invalid_channel'], 400, $response);
        }

        if ($userId === '' || !$this->participantRepo->isParticipant($conversationId, $userId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        // Client-published message: persist + notify, return the full event so
        // Centrifugo broadcasts it to all subscribers (incl. the sender).
        if (isset($payload['message']) && is_array($payload['message'])) {
            return $this->handleMessage($userId, $conversationId, $payload['message'], $response);
        }

        // Client-published reactions: persist and broadcast the new summary.
        if (isset($payload['reaction']) && is_array($payload['reaction'])) {
            return $this->handleReaction($userId, $conversationId, $payload['reaction'], $response);
        }

        // Only typing is accepted beyond this point; reject unknown payloads
        // instead of silently broadcasting them as typing.
        if (!array_key_exists('typing', $payload)) {
            return ResponseFactory::json(['error' => 'invalid_publish'], 400, $response);
        }

        // Rate limit typing events
        if (!$this->rateLimiter->isAllowed('chat_typing:' . $userId, self::TYPING_RATE_LIMIT, self::TYPING_RATE_WINDOW)) {
            return ResponseFactory::json(['error' => 'rate_limit_exceeded'], 429, $response);
        }

        // Sanitize: only allow typing boolean
        $sanitized = [
            'typing' => !empty($payload['typing']),
        ];

        // Typing is ephemeral: keep it out of channel history/recovery.
        return ResponseFactory::json([
            'result' => [
                'data' => $sanitized,
                'skip_history' => true,
            ],
        ], 200, $response);
    }

    /**
     * Handle a client-published message: persist via the chat service and
     * return the `message_created` event for Centrifugo to broadcast.
     *
     * The service is called with broadcasting disabled so the proxy response
     * is the single source of the publication (no duplicate event).
     *
     * @param array<string, mixed> $message
     */
    private function handleMessage(
        string $userId,
        string $conversationId,
        array $message,
        ResponseInterface $response,
    ): ResponseInterface {
        try {
            $formatted = $this->messageService->sendMessage($userId, $conversationId, $message, false);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        // Not ephemeral: Centrifugo stores this in history for recovery.
        return ResponseFactory::json([
            'result' => [
                'data' => [
                    'type' => 'message_created',
                    'message' => $formatted,
                ],
            ],
        ], 200, $response);
    }

    /**
     * Map a service error code to the matching HTTP status for the proxy.
     */
    private function errorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'conversation_not_found' => 404,
            'forbidden' => 403,
            'rate_limit_exceeded' => 429,
            default => 400,
        };
        return ResponseFactory::json(['error' => $error], $status, $response);
    }

    /**
     * Handle a client-published reaction: rate limit, persist, return the
     * updated summary which Centrifugo broadcasts to all subscribers.
     *
     * Request payload: { "reaction": { "messageId": "<uuid>", "emoji": "👍", "add": true } }
     *
     * @param array<string, mixed> $reaction
     */
    private function handleReaction(
        string $userId,
        string $conversationId,
        array $reaction,
        ResponseInterface $response,
    ): ResponseInterface {
        if (!$this->rateLimiter->isAllowed('chat_reaction:' . $userId, self::REACTION_RATE_LIMIT, self::REACTION_RATE_WINDOW)) {
            return ResponseFactory::json(['error' => 'rate_limit_exceeded'], 429, $response);
        }

        $messageId = (string) ($reaction['messageId'] ?? '');
        $emoji = (string) ($reaction['emoji'] ?? '');
        $add = !empty($reaction['add']);

        try {
            $reactions = $this->reactionService->applyReaction(
                $userId,
                $conversationId,
                $messageId,
                $emoji,
                $add,
            );
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        // Ephemeral: clients reload the full message state via REST on recovery.
        return ResponseFactory::json([
            'result' => [
                'data' => [
                    'type' => 'reaction_updated',
                    'messageId' => $messageId,
                    'reactions' => $reactions,
                ],
                'skip_history' => true,
            ],
        ], 200, $response);
    }

    private function parseConversationId(string $channel): ?string
    {
        if (!preg_match('/^chat:([A-Za-z0-9_-]+)$/', $channel, $m)) {
            return null;
        }
        return $m[1];
    }

    private function parseUserChannel(string $channel): ?string
    {
        if (!preg_match('/^user:([A-Za-z0-9_-]+)$/', $channel, $m)) {
            return null;
        }
        return $m[1];
    }
}
