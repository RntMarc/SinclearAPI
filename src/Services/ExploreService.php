<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Sinclear\Api\Repository\DiscoverBookmarkRepository;
use Sinclear\Api\Repository\DiscoverGastronomyRepository;
use Sinclear\Api\Repository\DiscoverPlaceRepository;

final readonly class ExploreService
{
    private Client $httpClient;

    private const string NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';
    private const string OSM_ATTRIBUTION = '© OpenStreetMap contributors';

    private const int NOMINATIM_TIMEOUT = 10;
    private const int NOMINATIM_RETRY_DELAY_US = 1_000_000;

    private const array GASTRONOMY_AMENITIES = [
        'restaurant', 'cafe', 'pub', 'bar', 'fast_food', 'food_court',
        'ice_cream', 'bakery', 'pastry', 'bistro',
    ];

    private const array LEISURE_AMENITIES = [
        'cinema', 'theatre', 'park', 'library', 'museum', 'art_gallery',
        'gallery', 'nightclub', 'casino', 'sports_centre', 'swimming_pool',
        'swimming_area', 'fitness_centre', 'concert_hall',
    ];

    private const array GASTRONOMY_SHOPS = [
        'bakery', 'confectionery', 'butcher', 'seafood', 'chocolate',
        'pastry', 'deli', 'cheese',
    ];

    public function __construct(
        private DiscoverPlaceRepository $placeRepo,
        private DiscoverGastronomyRepository $gastronomyRepo,
        private DiscoverBookmarkRepository $bookmarkRepo,
        private NominatimRateLimiter $rateLimiter,
        private NominatimCache $nominatimCache,
    ) {
        $this->httpClient = new Client([
            'timeout' => self::NOMINATIM_TIMEOUT,
            'headers' => [
                'User-Agent' => 'SinclearBeyondAPI/2.0 (https://sinclear.app)',
            ],
        ]);
    }

    public function fetchOsmData(int $osmId, string $osmType): array
    {
        $typeMap = ['N' => 'node', 'W' => 'way', 'R' => 'relation'];
        $type = $typeMap[$osmType] ?? throw new \InvalidArgumentException('Invalid osmType');

        $cacheKey = 'lookup|' . $type[0] . $osmId;
        $cached = $this->nominatimCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->rateLimiter->waitForSlot();

        $response = $this->nominatimRequest('GET', self::NOMINATIM_BASE . '/lookup', [
            'query' => [
                'osm_ids' => $type[0] . $osmId,
                'format' => 'json',
                'addressdetails' => 1,
                'extratags' => 1,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException('OSM object not found');
        }

        $this->nominatimCache->set($cacheKey, $data[0]);

        return $data[0];
    }

    public function geocodeLocation(string $query): ?array
    {
        $cacheKey = 'search|' . $query;
        $cached = $this->nominatimCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->rateLimiter->waitForSlot();

        $response = $this->nominatimRequest('GET', self::NOMINATIM_BASE . '/search', [
            'query' => [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || empty($data)) {
            return null;
        }

        $result = [
            'lat' => (float) $data[0]['lat'],
            'lon' => (float) $data[0]['lon'],
        ];

        $this->nominatimCache->set($cacheKey, $result);

        return $result;
    }

    public function determineCategory(array $osmData): string
    {
        $tags = $osmData['extratags'] ?? [];
        $address = $osmData['address'] ?? [];
        $osmType = $osmData['osm_type'] ?? '';
        $category = $osmData['category'] ?? '';

        $amenity = $tags['amenity'] ?? $category;
        $cuisine = $tags['cuisine'] ?? '';
        $shop = $tags['shop'] ?? '';
        $leisure = $tags['leisure'] ?? '';
        $tourism = $tags['tourism'] ?? '';
        $historic = $tags['historic'] ?? '';

        if ($cuisine !== '') {
            return 'gastronomy';
        }

        if (in_array($amenity, self::GASTRONOMY_AMENITIES, true)) {
            return 'gastronomy';
        }

        if (in_array($shop, self::GASTRONOMY_SHOPS, true)) {
            return 'gastronomy';
        }

        if (in_array($amenity, self::LEISURE_AMENITIES, true)) {
            return 'leisure';
        }

        if ($leisure !== '' || $tourism !== '' || $historic !== '') {
            return 'leisure';
        }

        return 'leisure';
    }

    public function buildFromOsm(array $osmData, int $osmId, string $osmType, string $creatorId): array
    {
        $tags = $osmData['extratags'] ?? [];
        $addressParts = $osmData['address'] ?? [];

        $name = $osmData['name'] ?? $tags['name'] ?? '';
        if ($name === '') {
            throw new \RuntimeException('OSM object has no name');
        }

        $addressStr = $osmData['display_name'] ?? '';
        if ($addressStr === '' && !empty($addressParts)) {
            $parts = array_filter([
                $addressParts['road'] ?? '',
                $addressParts['house_number'] ?? '',
                $addressParts['postcode'] ?? '',
                $addressParts['city'] ?? $addressParts['town'] ?? $addressParts['village'] ?? '',
                $addressParts['country'] ?? '',
            ]);
            $addressStr = implode(', ', $parts);
        }

        $category = $this->determineCategory($osmData);

        $result = [
            'name' => $name,
            'description' => $tags['description'] ?? $tags['note'] ?? null,
            'category' => $category,
            'address' => $addressStr ?: null,
            'latitude' => $osmData['lat'] ?? null,
            'longitude' => $osmData['lon'] ?? null,
            'osmId' => $osmId,
            'osmType' => $osmType,
            'phone' => $tags['phone'] ?? null,
            'website' => $tags['website'] ?? $tags['url'] ?? null,
            'email' => $tags['email'] ?? null,
            'openingHours' => $tags['opening_hours'] ?? null,
            'cuisine' => $tags['cuisine'] ?? null,
            'creatorId' => $creatorId,
        ];

        if ($result['latitude'] !== null) {
            $result['latitude'] = (float) $result['latitude'];
        }
        if ($result['longitude'] !== null) {
            $result['longitude'] = (float) $result['longitude'];
        }

        return $result;
    }

    public function createPlace(int $osmId, string $osmType, string $userId): array
    {
        $existing = $this->placeRepo->findByOsmId($osmId, $osmType);
        if ($existing !== null) {
            throw new \RuntimeException('Place already exists');
        }

        $osmData = $this->fetchOsmData($osmId, $osmType);
        $placeData = $this->buildFromOsm($osmData, $osmId, $osmType, $userId);

        $cuisine = $placeData['cuisine'];
        unset($placeData['cuisine']);

        $placeId = $this->placeRepo->create($placeData);

        if ($cuisine !== null && $placeData['category'] === 'gastronomy') {
            $this->gastronomyRepo->create($placeId, $cuisine);
        }

        $place = $this->placeRepo->findById($placeId);
        return $this->formatPlace($place);
    }

    public function refreshPlace(string $id): array
    {
        $place = $this->placeRepo->findById($id);
        if ($place === null) {
            throw new \RuntimeException('Place not found');
        }

        $osmData = $this->fetchOsmData((int) $place['osmId'], $place['osmType']);
        $updated = $this->buildFromOsm($osmData, (int) $place['osmId'], $place['osmType'], $place['creatorId']);

        $cuisine = $updated['cuisine'];
        unset($updated['cuisine'], $updated['creatorId']);

        $this->placeRepo->update($id, $updated);

        if ($updated['category'] === 'gastronomy') {
            if ($cuisine !== null) {
                $this->gastronomyRepo->update($id, $cuisine);
            }
        } else {
            $this->gastronomyRepo->delete($id);
        }

        $place = $this->placeRepo->findById($id);
        return $this->formatPlace($place);
    }

    public function getPlace(string $id): ?array
    {
        $place = $this->placeRepo->findById($id);
        if ($place === null) {
            return null;
        }
        return $this->formatPlace($place);
    }

    public function listPlaces(?string $category, int $page, int $limit, ?string $sort = null, ?string $cuisine = null): array
    {
        $result = $this->placeRepo->list($category, $page, $limit, $sort, $cuisine);
        $result['data'] = array_map(fn(array $p) => $this->formatPlace($p), $result['data']);
        return $result;
    }

    public function randomPlaces(int $limit, ?string $category = null): array
    {
        $places = $this->placeRepo->random($limit, $category);
        return array_map(fn(array $p) => $this->formatPlace($p), $places);
    }

    public function getBookmarkStatus(string $userId, string $placeId): bool
    {
        return $this->bookmarkRepo->find($userId, $placeId) !== null;
    }

    public function setBookmark(string $userId, string $placeId): array
    {
        $existing = $this->bookmarkRepo->find($userId, $placeId);
        if ($existing !== null) {
            throw new \RuntimeException('Place already bookmarked');
        }
        $id = $this->bookmarkRepo->create($userId, $placeId);
        return ['id' => $id, 'bookmarked' => true];
    }

    public function removeBookmark(string $userId, string $placeId): void
    {
        $this->bookmarkRepo->delete($userId, $placeId);
    }

    public function listBookmarks(string $userId, int $page, int $limit): array
    {
        $result = $this->bookmarkRepo->listByUser($userId, $page, $limit);
        $result['data'] = array_map(fn(array $p) => $this->formatPlace($p), $result['data']);
        return $result;
    }

    public function searchPlaces(array $params): array
    {
        if (!empty($params['location']) && empty($params['lat'])) {
            $coords = $this->geocodeLocation($params['location']);
            if ($coords !== null) {
                $params['lat'] = $coords['lat'];
                $params['lon'] = $coords['lon'];
                $params['radius'] ??= 5000;
            }
        }

        $result = $this->placeRepo->search($params);
        $result['data'] = array_map(fn(array $p) => $this->formatPlace($p), $result['data']);
        return $result;
    }

    public function deletePlace(string $id): void
    {
        $this->placeRepo->delete($id);
    }

    private function nominatimRequest(string $method, string $url, array $options): ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to fetch OSM data: ' . $e->getMessage());
        }

        if ($response->getStatusCode() === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');
            $delay = is_numeric($retryAfter) ? (int) $retryAfter * 1_000_000 : self::NOMINATIM_RETRY_DELAY_US;

            usleep($delay);

            try {
                $response = $this->httpClient->request($method, $url, $options);
            } catch (GuzzleException $e) {
                throw new \RuntimeException('Nominatim rate-limited despite retry: ' . $e->getMessage());
            }
        }

        return $response;
    }

    private function formatPlace(array $place): array
    {
        $gastronomy = $place['category'] === 'gastronomy'
            ? $this->gastronomyRepo->findByPlaceId($place['id'])
            : null;

        $result = [
            'id' => $place['id'],
            'name' => $place['name'],
            'description' => $place['description'],
            'category' => $place['category'],
            'address' => $place['address'],
            'latitude' => $place['latitude'] !== null ? (float) $place['latitude'] : null,
            'longitude' => $place['longitude'] !== null ? (float) $place['longitude'] : null,
            'osmId' => $place['osmId'] !== null ? (int) $place['osmId'] : null,
            'osmType' => $place['osmType'],
            'phone' => $place['phone'],
            'website' => $place['website'],
            'email' => $place['email'],
            'openingHours' => $place['openingHours'],
            'creatorId' => $place['creatorId'],
            'createdAt' => $place['createdAt'],
            'lastUpdated' => $place['lastUpdated'],
            'bookmarkedAt' => $place['bookmarkedAt'] ?? null,
            '_attribution' => self::OSM_ATTRIBUTION,
        ];

        if ($gastronomy !== null) {
            $result['cuisine'] = $gastronomy['cuisine'];
        }

        if (isset($place['distance'])) {
            $result['distance'] = (float) $place['distance'];
        }

        if (isset($place['avg_rating'])) {
            $result['avgRating'] = (float) $place['avg_rating'];
        }

        return $result;
    }
}
