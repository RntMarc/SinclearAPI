<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ChatParticipantRepository;
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

    public function __construct(
        private ChatParticipantRepository $participantRepo,
        private RateLimiter $rateLimiter,
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
     * Centrifugo publish proxy: validates participant + rate limit for typing events.
     *
     * Request:  { "user": "<userId>", "channel": "chat:<conversationId>", "data": { "typing": true } }
     * Response: { "result": { "data": { "typing": true } } } on success
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
