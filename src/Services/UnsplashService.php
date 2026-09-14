<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\ExternalDataCacheRepository;

/**
 * Fetches a user's public Unsplash photos by username and caches the result.
 *
 * Unsplash rate limits are strict (the free demo key allows only 50 requests
 * per hour), so every per-user response is stored in the shared
 * ExternalDataCache under `data_type = unsplash_user_photos` and
 * `location_key = <username>`. Visibility filtering happens in the
 * PhotoController; this service only returns raw, normalized photos.
 */
final readonly class UnsplashService
{
    private const int HTTP_TIMEOUT = 10;
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';
    private const string CACHE_TYPE = 'unsplash_user_photos';

    private Client $httpClient;

    public function __construct(
        private ExternalDataCacheRepository $cacheRepo,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
        $this->httpClient = new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Returns the newest photos of a user, or null when the handle is
     * unknown or the API is unavailable. Null signals "skip this user" to
     * the caller; it is never cached, so a transient failure is retried on
     * the next request.
     *
     * @return list<array<string, mixed>>|null
     */
    public function getUserPhotos(string $username): ?array
    {
        $accessKey = (string) ($this->settings->unsplash['access_key'] ?? '');
        if ($accessKey === '') {
            return null;
        }

        $cacheKey = strtolower(trim($username));
        if ($cacheKey === '') {
            return null;
        }

        $cached = $this->cacheRepo->find(self::CACHE_TYPE, $cacheKey);
        if ($cached !== null) {
            return is_array($cached['payload']) ? $cached['payload'] : [];
        }

        $limit = (int) ($this->settings->unsplash['per_user_limit'] ?? 30);
        $photos = $this->fetchFromApi($username, $limit, $accessKey);
        if ($photos === null) {
            return null;
        }

        $ttl = (int) ($this->settings->unsplash['cache_ttl'] ?? 21600);
        $this->cacheRepo->save(self::CACHE_TYPE, $cacheKey, 'unsplash', $photos, $ttl);

        return $photos;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchFromApi(string $username, int $limit, string $accessKey): ?array
    {
        $baseUrl = rtrim((string) ($this->settings->unsplash['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $url = $baseUrl . '/users/' . rawurlencode($username) . '/photos';

        try {
            $response = $this->httpClient->get($url, [
                'query' => [
                    'client_id' => $accessKey,
                    'order_by' => 'latest',
                    'per_page' => max(1, $limit),
                    'page' => 1,
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('UnsplashService: request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            return null;
        }

        $photos = [];
        foreach ($body as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $normalized = $this->normalize($photo);
            if ($normalized['id'] !== '' && $normalized['thumb'] !== null) {
                $photos[] = $normalized;
            }
        }

        return $photos;
    }

    /**
     * @param array<string, mixed> $photo
     * @return array<string, mixed>
     */
    private function normalize(array $photo): array
    {
        $urls = is_array($photo['urls'] ?? null) ? $photo['urls'] : [];
        $user = is_array($photo['user'] ?? null) ? $photo['user'] : [];
        $userLinks = is_array($user['links'] ?? null) ? $user['links'] : [];

        return [
            'id' => (string) ($photo['id'] ?? ''),
            'thumb' => $this->stringOrNull($urls['small'] ?? $urls['thumb'] ?? null),
            'regular' => $this->stringOrNull($urls['regular'] ?? null),
            'width' => (int) ($photo['width'] ?? 0),
            'height' => (int) ($photo['height'] ?? 0),
            'createdAt' => $this->formatDate($photo['created_at'] ?? null),
            'photographer' => [
                'name' => $this->stringOrNull($user['name'] ?? null),
                'username' => $this->stringOrNull($user['username'] ?? null),
                'url' => $this->stringOrNull($userLinks['html'] ?? null),
            ],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function formatDate(mixed $iso): string
    {
        if (!is_string($iso) || $iso === '') {
            return '';
        }
        $timestamp = strtotime($iso);
        return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }
}
