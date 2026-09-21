<?php

namespace Sinclear\Api\Services\Centrifugo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around Guzzle for the Centrifugo Server API.
 *
 * All methods are fire-and-forget: errors are logged but never thrown.
 * When CENTRIFUGO_ENABLED=false, all methods are no-ops.
 */
final readonly class CentrifugoClient implements CentrifugoClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $apiUrl,
        private int $timeout,
        private bool $enabled,
        private LoggerInterface $logger,
        private Client $httpClient,
    ) {}

    /**
     * Publish data to a Centrifugo channel.
     */
    public function publish(string $channel, array $data, bool $skipHistory = false): void
    {
        if (!$this->enabled) {
            return;
        }

        $body = [
            'channel' => $channel,
            'data' => $data,
        ];
        if ($skipHistory) {
            $body['skip_history'] = true;
        }

        $this->request('publish', $body);
    }

    /**
     * Get presence info for a channel, normalized to unique user IDs.
     *
     * Centrifugo returns presence keyed by client ID:
     *   {"<clientId>": {"client": "<clientId>", "user": "<userId>"}}
     * We key by user ID (deduped across multiple connections of one user),
     * so callers can compare directly against `ChatParticipant.userId`.
     *
     * Returns the normalized presence on success, empty array on failure.
     *
     * @return array<string, array{client: string, user: string}>
     */
    public function presence(string $channel): array
    {
        if (!$this->enabled) {
            return [];
        }

        $result = $this->request('presence', [
            'channel' => $channel,
        ]);

        $presence = $result['presence'] ?? [];
        if (!is_array($presence)) {
            return [];
        }

        $byUser = [];
        foreach ($presence as $clientId => $info) {
            if (!is_array($info)) {
                continue;
            }
            $userId = (string) ($info['user'] ?? '');
            if ($userId === '' || isset($byUser[$userId])) {
                continue;
            }
            $byUser[$userId] = [
                'client' => (string) ($info['client'] ?? $clientId),
                'user' => $userId,
            ];
        }

        return $byUser;
    }

    /**
     * Get user presence for the user:<userId> channel.
     *
     * Returns normalized presence with online status and timestamps.
     * On Centrifugo failure or disabled: online=false, timestamps=null.
     */
    public function getUserPresence(string $userId): array
    {
        if (!$this->enabled) {
            return ['online' => false, 'lastJoin' => null, 'lastLeave' => null];
        }

        $result = $this->request('presence', [
            'channel' => "user:{$userId}",
        ]);

        $presence = $result['presence'] ?? [];
        if (!is_array($presence) || $presence === []) {
            return ['online' => false, 'lastJoin' => null, 'lastLeave' => null];
        }

        $firstClient = reset($presence);
        if (!is_array($firstClient)) {
            return ['online' => false, 'lastJoin' => null, 'lastLeave' => null];
        }

        return [
            'online' => true,
            'lastJoin' => isset($firstClient['info']) && is_array($firstClient['info'])
                ? ($firstClient['info']['lastJoin'] ?? null) : null,
            'lastLeave' => isset($firstClient['info']) && is_array($firstClient['info'])
                ? ($firstClient['info']['lastLeave'] ?? null) : null,
        ];
    }

    /**
     * Get user presence for multiple users in bulk.
     */
    public function getBulkUserPresence(array $userIds): array
    {
        $results = [];
        foreach ($userIds as $userId) {
            $results[$userId] = $this->getUserPresence($userId);
        }
        return $results;
    }

    /**
     * Unsubscribe a user from a channel.
     */
    public function unsubscribe(string $channel, string $userId): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->request('unsubscribe', [
            'channel' => $channel,
            'user' => $userId,
        ]);
    }

    /**
     * Disconnect a user from Centrifugo entirely.
     */
    public function disconnect(string $userId): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->request('disconnect', [
            'user' => $userId,
        ]);
    }

    /**
     * Execute a Centrifugo Server API request.
     *
     * @return array<string, mixed> Result data on success, empty array on failure
     */
    private function request(string $method, array $body): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->apiUrl, '/') . '/' . $method,
                [
                    'headers' => [
                        'X-API-Key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->timeout,
                ],
            );

            $result = json_decode((string) $response->getBody(), true);
            if (!is_array($result)) {
                $this->logger->warning('[CENTRIFUGO] Invalid response from {method}', [
                    'method' => $method,
                    'status' => $response->getStatusCode(),
                ]);
                return [];
            }

            // Centrifugo returns HTTP 200 even on API errors; the error is in the body.
            if (isset($result['error'])) {
                $this->logger->warning('[CENTRIFUGO] {method} error {code}: {message}', [
                    'method' => $method,
                    'code' => $result['error']['code'] ?? 0,
                    'message' => $result['error']['message'] ?? '',
                ]);
                return [];
            }

            return $result['result'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->warning('[CENTRIFUGO] {method} failed: {error}', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
