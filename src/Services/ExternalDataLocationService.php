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
     * Uses Nominatim for global geocoding. Results matching InfraNode-supported
     * cities (via OSM relation ID mapping) get the InfraNode slug and recommended=true.
     *
     * @return array{data: list<array{name: string, slug: ?string, lat: float, lon: float, recommended: bool, source: string, state: ?string, population: ?int, osm_id: ?int, osm_type: ?string}>}
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['data' => []];
        }

        // Load OSM-to-slug mapping
        $osmToSlugMap = $this->loadOsmToSlugMapping();

        // Search Nominatim (global coverage, returns OSM IDs)
        $nominatimResults = $this->searchNominatim($query);

        $results = [];
        foreach ($nominatimResults as $place) {
            $osmId = $place['osm_id'] ?? null;
            $osmType = $place['osm_type'] ?? null;

            // Check if this is a relation (type 'R') that matches an InfraNode city
            $slug = null;
            $recommended = false;
            $source = 'nominatim';

            if ($osmType === 'R' && $osmId !== null && isset($osmToSlugMap[$osmId])) {
                $slug = $osmToSlugMap[$osmId];
                $recommended = true;
                $source = 'infranode';
            }

            $results[] = [
                'name' => $place['display_name'],
                'slug' => $slug,
                'lat' => (float) $place['lat'],
                'lon' => (float) $place['lon'],
                'recommended' => $recommended,
                'source' => $source,
                'state' => $place['state'] ?? null,
                'population' => null,
                'osm_id' => $osmId,
                'osm_type' => $osmType,
            ];
        }

        // Limit total results
        $results = array_slice($results, 0, self::MAX_RESULTS);

        return ['data' => $results];
    }

    /**
     * Load OSM relation ID to InfraNode slug mapping.
     *
     * @return array<int, string>
     */
    private function loadOsmToSlugMapping(): array
    {
        $mappingPath = __DIR__ . '/../../config/infranode_osm_mapping.php';
        if (is_file($mappingPath)) {
            $mapping = require $mappingPath;
            if (is_array($mapping)) {
                return $mapping;
            }
        }
        return [];
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
     * @return list<array{display_name: string, lat: string, lon: string, state: ?string, osm_id: ?int, osm_type: ?string}>
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
                    'extratags' => 1,
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

            // Normalize results - include OSM ID and type for mapping
            $normalized = [];
            foreach ($results as $item) {
                $normalized[] = [
                    'display_name' => $item['display_name'] ?? '',
                    'lat' => $item['lat'] ?? '0',
                    'lon' => $item['lon'] ?? '0',
                    'state' => $item['address']['state'] ?? null,
                    'osm_id' => isset($item['osm_id']) ? (int) $item['osm_id'] : null,
                    'osm_type' => $item['osm_type'] ?? null,
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