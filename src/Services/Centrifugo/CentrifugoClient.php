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
    public function publish(string $channel, array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->request('publish', [
            'channel' => $channel,
            'data' => $data,
        ]);
    }

    /**
     * Get presence info for a channel (connected clients).
     *
     * Returns the presence data on success, empty array on failure.
     *
     * @return array<string, mixed>
     */
    public function presence(string $channel): array
    {
        if (!$this->enabled) {
            return [];
        }

        $result = $this->request('presence', [
            'channel' => $channel,
        ]);

        return $result['presence'] ?? [];
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
