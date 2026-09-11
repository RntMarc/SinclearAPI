<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\UserWeatherLocationRepository;

final readonly class UserWeatherLocationService
{
    private const int MAX_LOCATIONS = 5;

    public function __construct(
        private UserWeatherLocationRepository $repo,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function list(string $userId): array
    {
        return $this->repo->listByUserId($userId);
    }

    /** @return array<string, mixed> */
    public function create(
        string $userId,
        string $name,
        ?string $slug,
        float $lat,
        float $lon,
        string $source,
    ): array {
        if ($this->repo->countByUserId($userId) >= self::MAX_LOCATIONS) {
            throw new \InvalidArgumentException('max_locations_reached');
        }

        $sortOrder = $this->repo->countByUserId($userId);
        $id = $this->repo->create($userId, $name, $slug, $lat, $lon, $source, $sortOrder);

        return $this->repo->findById($id);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, string $userId, array $data): array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null || $existing['userId'] !== $userId) {
            throw new \InvalidArgumentException('location_not_found');
        }

        $this->repo->update(
            id: $id,
            userId: $userId,
            name: $data['name'] ?? $existing['name'],
            slug: $data['slug'] ?? $existing['slug'],
            lat: isset($data['lat']) ? (float) $data['lat'] : $existing['lat'],
            lon: isset($data['lon']) ? (float) $data['lon'] : $existing['lon'],
            source: $data['source'] ?? $existing['source'],
            sortOrder: isset($data['sortOrder']) ? (int) $data['sortOrder'] : $existing['sortOrder'],
        );

        return $this->repo->findById($id);
    }

    public function delete(string $id, string $userId): void
    {
        $existing = $this->repo->findById($id);
        if ($existing === null || $existing['userId'] !== $userId) {
            throw new \InvalidArgumentException('location_not_found');
        }

        $this->repo->delete($id, $userId);
    }

    /**
     * Replace all locations at once (full sync).
     *
     * @param list<array{name: string, slug?: string|null, lat: float, lon: float, source?: string}> $locations
     * @return array<int, array<string, mixed>>
     */
    public function replaceAll(string $userId, array $locations): array
    {
        if (count($locations) > self::MAX_LOCATIONS) {
            throw new \InvalidArgumentException('max_locations_reached');
        }

        $this->repo->deleteAllAndReplace($userId, $locations);

        return $this->list($userId);
    }
}
