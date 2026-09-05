<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final readonly class ExternalDataLocationService
{
    private Client $httpClient;

    private const int HTTP_TIMEOUT = 10;
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';
    private const string INFRANODE_CITIES_URL = 'https://infranode.dev/api/cities';
    private const int CACHE_TTL = 86400; // 24 hours
    private const int MIN_QUERY_LENGTH = 2;
    private const int MAX_RESULTS = 20;

    public function __construct(
        private NominatimCache $nominatimCache,
        private NominatimRateLimiter $nominatimRateLimiter,
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
     * Search for weather locations by query string.
     *
     * Returns a unified list of:
     * - InfraNode supported cities (recommended: true, slug provided)
     * - Nominatim geocoding results (recommended: false, slug null)
     *
     * @return array{data: list<array{name: string, slug: ?string, lat: float, lon: float, recommended: bool, source: string, state: ?string, population: ?int}>}
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['data' => []];
        }

        $results = [];

        // 1. Search InfraNode cities (recommended, with slug)
        $infranodeResults = $this->searchInfraNodeCities($query);
        foreach ($infranodeResults as $city) {
            $results[] = [
                'name' => $city['name_de'],
                'slug' => $city['slug'],
                'lat' => $city['geo']['lat'],
                'lon' => $city['geo']['lon'],
                'recommended' => true,
                'source' => 'infranode',
                'state' => $city['state'] ?? null,
                'population' => $city['population'] ?? null,
            ];
        }

        // 2. Search Nominatim for geocoding (arbitrary worldwide locations)
        $nominatimResults = $this->searchNominatim($query);
        foreach ($nominatimResults as $place) {
            $results[] = [
                'name' => $place['display_name'],
                'slug' => null,
                'lat' => (float) $place['lat'],
                'lon' => (float) $place['lon'],
                'recommended' => false,
                'source' => 'nominatim',
                'state' => $place['state'] ?? null,
                'population' => null,
            ];
        }

        // Limit total results
        $results = array_slice($results, 0, self::MAX_RESULTS);

        return ['data' => $results];
    }

    /**
     * List all InfraNode supported cities (for admin/debug use).
     *
     * @return array{data: list<array{name: string, slug: string, lat: float, lon: float, state: string, population: int, coverage: string}>}
     */
    public function listInfraNodeCities(): array
    {
        $cities = $this->fetchInfraNodeCities();

        $results = [];
        foreach ($cities as $city) {
            $results[] = [
                'name' => $city['name_de'],
                'slug' => $city['slug'],
                'lat' => $city['geo']['lat'],
                'lon' => $city['geo']['lon'],
                'state' => $city['state'],
                'population' => $city['population'],
                'coverage' => $city['coverage'],
            ];
        }

        return ['data' => $results];
    }

    // =========================================================================
    // InfraNode
    // =========================================================================

    /**
     * Fetch and cache InfraNode city list.
     *
     * @return list<array>
     */
    private function fetchInfraNodeCities(): array
    {
        $cachePath = $this->getInfraNodeCachePath();

        // Use file cache (static data, 24h TTL)
        if (is_file($cachePath) && (time() - filemtime($cachePath)) < self::CACHE_TTL) {
            $content = file_get_contents($cachePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        try {
            $response = $this->httpClient->get(self::INFRANODE_CITIES_URL);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                $this->logger->warning('ExternalDataLocationService: Invalid InfraNode response format');
                return [];
            }

            $cities = $decoded['data'];

            // Cache to file
            if (!is_dir(dirname($cachePath))) {
                mkdir(dirname($cachePath), 0775, true);
            }
            file_put_contents($cachePath, json_encode($cities), LOCK_EX);

            return $cities;
        } catch (\Throwable $e) {
            $this->logger->warning('ExternalDataLocationService: Failed to fetch InfraNode cities: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search InfraNode cities by name or slug (case-insensitive).
     *
     * Priority order:
     * 1. Exact slug match
     * 2. Slug starts with query
     * 3. Name starts with query
     * 4. Slug contains query
     * 5. Name contains query
     *
     * Within each tier, sorted by population (descending).
     *
     * @param list<array> $cities
     * @return list<array>
     */
    private function searchInfraNodeCities(string $query): array
    {
        $cities = $this->fetchInfraNodeCities();
        $queryLower = mb_strtolower($query);

        $exactSlug = [];
        $slugPrefix = [];
        $namePrefix = [];
        $slugContains = [];
        $nameContains = [];

        foreach ($cities as $city) {
            $name = mb_strtolower($city['name_de'] ?? '');
            $slug = mb_strtolower($city['slug'] ?? '');

            if ($slug === $queryLower) {
                $exactSlug[] = $city;
            } elseif (str_starts_with($slug, $queryLower)) {
                $slugPrefix[] = $city;
            } elseif (str_starts_with($name, $queryLower)) {
                $namePrefix[] = $city;
            } elseif (str_contains($slug, $queryLower)) {
                $slugContains[] = $city;
            } elseif (str_contains($name, $queryLower)) {
                $nameContains[] = $city;
            }
        }

        $sortByPop = static fn (array $a, array $b): int =>
            ($b['population'] ?? 0) <=> ($a['population'] ?? 0);

        usort($exactSlug, $sortByPop);
        usort($slugPrefix, $sortByPop);
        usort($namePrefix, $sortByPop);
        usort($slugContains, $sortByPop);
        usort($nameContains, $sortByPop);

        $results = array_merge($exactSlug, $slugPrefix, $namePrefix, $slugContains, $nameContains);

        return array_slice($results, 0, 10);
    }

    private function getInfraNodeCachePath(): string
    {
        return __DIR__ . '/../../var/cache/infranode/cities.json';
    }

    // =========================================================================
    // Nominatim
    // =========================================================================

    /**
     * Search Nominatim for geocoding results.
     *
     * @return list<array{display_name: string, lat: string, lon: string, state: ?string}>
     */
    private function searchNominatim(string $query): array
    {
        $cacheKey = 'nominatim_search_' . mb_strtolower($query);
        $cached = $this->nominatimCache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        try {
            $this->nominatimRateLimiter->waitForSlot();

            $response = $this->httpClient->get('https://nominatim.openstreetmap.org/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 10,
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'Accept-Language' => 'de,en',
                ],
            ]);

            $body = (string) $response->getBody();
            $results = json_decode($body, true);

            if (!is_array($results)) {
                return [];
            }

            // Normalize results
            $normalized = [];
            foreach ($results as $item) {
                $normalized[] = [
                    'display_name' => $item['display_name'] ?? '',
                    'lat' => $item['lat'] ?? '0',
                    'lon' => $item['lon'] ?? '0',
                    'state' => $item['address']['state'] ?? null,
                ];
            }

            $this->nominatimCache->set($cacheKey, $normalized);

            return $normalized;
        } catch (\Throwable $e) {
            $this->logger->warning('ExternalDataLocationService: Nominatim search failed: ' . $e->getMessage());
            return [];
        }
    }
}
