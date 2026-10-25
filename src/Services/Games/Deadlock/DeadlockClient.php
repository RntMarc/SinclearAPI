<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Deadlock;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;

/**
 * Schlanker HTTP-Client fuer die Deadlock API.
 *
 * Es werden nur die fuer v1 benoetigten Endpunkte abgebildet. Der Client
 * basiert bewusst auf dem vorhandenen Guzzle 8 und nicht auf dem offiziellen
 * PHP-Client, der Guzzle 7 voraussetzt.
 */
final readonly class DeadlockClient
{
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';
    private const int HTTP_TIMEOUT = 15;

    private Client $httpClient;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(
        Settings $settings,
        private LoggerInterface $logger,
        ?Client $httpClient = null,
    ) {
        $games = $settings->games;
        $this->baseUrl = rtrim((string) ($games['deadlock_base_url'] ?? 'https://api.deadlock-api.com'), '/');
        $this->apiKey = (string) ($games['deadlock_api_key'] ?? '');
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param list<string> $accountIds
     * @return list<array<string, mixed>>
     */
    public function rankBatch(array $accountIds): array
    {
        return $this->request('/v1/players/rank', [
            'account_ids' => implode(',', $accountIds),
        ]);
    }

    /**
     * @param list<string> $accountIds
     * @return list<array<string, mixed>>
     */
    public function heroStats(array $accountIds): array
    {
        return $this->request('/v1/players/hero-stats', [
            'account_ids' => implode(',', $accountIds),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function matchHistory(string $accountId): array
    {
        return $this->request('/v1/players/' . rawurlencode($accountId) . '/match-history');
    }

    /**
     * @param list<string> $accountIds
     * @return list<array<string, mixed>>
     */
    public function steamProfiles(array $accountIds, bool $refresh = false): array
    {
        try {
            return $this->request('/v1/players/steam', [
                'account_ids' => implode(',', $accountIds),
                'refresh' => $refresh ? 'true' : 'false',
            ]);
        } catch (DeadlockApiException $e) {
            // Kein Steam-Profil vorhanden: die Verknuepfung wird abgelehnt.
            if ($e->status === 404) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function request(string $path, array $query = []): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json',
        ];
        if ($this->apiKey !== '') {
            $headers['X-API-KEY'] = $this->apiKey;
        }

        try {
            $response = $this->httpClient->get($this->baseUrl . $path, [
                'query' => $query,
                'headers' => $headers,
            ]);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $retryAfter = $response->getHeaderLine('Retry-After');

            $this->logger->warning('Deadlock API request failed', [
                'path' => $path,
                'status' => $status,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
            ]);

            throw new DeadlockApiException(
                status: $status,
                retryAfter: $retryAfter !== '' ? (int) $retryAfter : null,
                message: 'deadlock_api_error',
            );
        } catch (RequestException $e) {
            $this->logger->warning('Deadlock API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new DeadlockApiException(
                status: 0,
                retryAfter: null,
                message: 'deadlock_api_unreachable',
            );
        } catch (GuzzleException $e) {
            $this->logger->warning('Deadlock API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new DeadlockApiException(
                status: 0,
                retryAfter: null,
                message: 'deadlock_api_unreachable',
            );
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, static fn (mixed $entry): bool => is_array($entry)));
    }
}
