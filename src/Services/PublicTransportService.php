<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\PublicTransportJourneyRepository;
use Sinclear\Api\Repository\TravelStopRepository;

final readonly class PublicTransportService
{
    private const API_BASE = 'https://v6.db.transport.rest';

    public function __construct(
        private Client $http,
        private PublicTransportJourneyRepository $journeyRepo,
        private TravelStopRepository $stopRepo,
        private LoggerInterface $logger,
    ) {}

    /** @return list<array> */
    public function searchStations(string $query, int $limit = 10): array
    {
        $local = $this->stopRepo->searchByNameFuzzy($query, $limit);
        if ($local !== []) {
            return array_map(fn(array $s) => $this->mapStopFromDb($s), $local);
        }

        if ($this->stopRepo->countAll() > 0) {
            return [];
        }

        try {
            $response = $this->http->get(self::API_BASE . '/locations', [
                'query' => [
                    'query' => $query,
                    'poi' => 'false',
                    'addresses' => 'false',
                    'results' => $limit,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body)) {
                return [];
            }

            $now = date('Y-m-d H:i:s.000');
            foreach ($body as $item) {
                $id = $item['id'] ?? null;
                if ($id === null || ($item['type'] ?? '') !== 'stop') {
                    continue;
                }
                $this->stopRepo->upsert($id, [
                    'name' => $item['name'] ?? '',
                    'ril100' => $item['ril100'] ?? null,
                    'latitude' => $item['location']['latitude'] ?? null,
                    'longitude' => $item['location']['longitude'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'products' => $item['products'] ?? null,
                    'lastUpdated' => $now,
                ]);
            }

            $local = $this->stopRepo->searchByNameFuzzy($query, $limit);
            return array_map(fn(array $s) => $this->mapStopFromDb($s), $local);
        } catch (\Throwable $e) {
            $this->logger->warning('DB station search failed: ' . $e->getMessage());
            throw new \RuntimeException('Stationen-Datenbank leer und DB API nicht erreichbar. Bitte zuerst Stationen aktualisieren via POST /api/v2/public-transport/stations/refresh');
        }
    }

    /** @return array{data: list<array>, refreshToken: ?string} */
    public function findJourneys(
        string $fromId,
        string $toId,
        ?string $departure,
        int $results = 5,
    ): array {
        $query = [
            'from' => $fromId,
            'to' => $toId,
            'results' => $results,
            'stopovers' => 'false',
        ];

        if ($departure !== null) {
            $query['departure'] = $departure . 'Z';
        }

        try {
            $response = $this->http->get(self::API_BASE . '/journeys', [
                'query' => $query,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body) || !isset($body['journeys'])) {
                return ['data' => [], 'refreshToken' => null];
            }

            $journeys = [];
            foreach ($body['journeys'] as $j) {
                $journeys[] = $this->mapJourneyFromApi($j);
            }

            return [
                'data' => $journeys,
                'refreshToken' => $body['refreshToken'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('DB journey search failed: ' . $e->getMessage());
            return ['data' => [], 'refreshToken' => null];
        }
    }

    /** @return array|null */
    public function refreshTrip(string $dbTripId): ?array
    {
        try {
            $response = $this->http->get(self::API_BASE . '/trips/' . $dbTripId, [
                'query' => ['stopovers' => 'true'],
            ]);
            return json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            $this->logger->warning("DB trip refresh failed for $dbTripId: " . $e->getMessage());
            return null;
        }
    }

    public function saveJourneyFromApi(string $creatorId, ?string $tripId, array $journeyApiData, array $additionalParticipantIds = []): string
    {
        $chosenAt = date('Y-m-d H:i:s');
        $refreshToken = $journeyApiData['refreshToken'] ?? null;

        $journeyId = $this->journeyRepo->create($creatorId, [
            'tripId' => $tripId,
            'chosenAt' => $chosenAt,
            'refreshToken' => $refreshToken,
            'participantIds' => $additionalParticipantIds,
        ]);

        $this->createLegsFromApi($journeyId, $journeyApiData);

        return $journeyId;
    }

    private function createLegsFromApi(string $journeyId, array $journeyApiData): void
    {
        $legs = $journeyApiData['legs'] ?? $journeyApiData;
        if (!is_array($legs)) {
            return;
        }

        foreach ($legs as $index => $leg) {
            $origin = $leg['origin'] ?? [];
            $destination = $leg['destination'] ?? [];
            $departure = $this->parseTime($leg['departure'] ?? $leg['plannedWhen'] ?? '');
            $arrival = $this->parseTime($leg['arrival'] ?? $leg['plannedArrival'] ?? '');

            if ($departure === null || $arrival === null) {
                continue;
            }

            $line = $leg['line'] ?? null;
            $isWalking = ($leg['walking'] ?? false) || ($leg['distance'] ?? 0) > 0 && $line === null;

            $this->journeyRepo->createLeg($journeyId, [
                'legIndex' => $index,
                'mode' => $isWalking ? 'walking' : ($line['product'] ?? 'train'),
                'lineName' => $line['name'] ?? null,
                'lineProduct' => $line['product'] ?? null,
                'originStopId' => (string) ($origin['id'] ?? ''),
                'destinationStopId' => (string) ($destination['id'] ?? ''),
                'originStopName' => $origin['name'] ?? '',
                'destinationStopName' => $destination['name'] ?? '',
                'dbTripId' => $leg['tripId'] ?? null,
                'plannedDeparture' => $departure,
                'plannedArrival' => $arrival,
                'actualDeparture' => isset($leg['departure']) && $leg['departure'] !== ($leg['plannedWhen'] ?? '')
                    ? $this->parseTime($leg['departure']) : null,
                'actualArrival' => isset($leg['arrival']) && $leg['arrival'] !== ($leg['plannedArrival'] ?? '')
                    ? $this->parseTime($leg['arrival']) : null,
                'departureDelay' => $leg['departureDelay'] ?? null,
                'arrivalDelay' => $leg['arrivalDelay'] ?? null,
                'departurePlatform' => $leg['platform'] ?? $leg['departurePlatform'] ?? null,
                'arrivalPlatform' => $leg['arrivalPlatform'] ?? null,
                'cancelled' => $leg['cancelled'] ?? false,
                'status' => $this->deriveLegStatus($leg),
                'rawResponse' => $leg,
            ]);

            $this->ensureStopExists($origin);
            $this->ensureStopExists($destination);
        }
    }

    public function refreshLegFromDb(array $leg): array
    {
        $dbTripId = $leg['dbTripId'];
        $tripData = $this->refreshTrip($dbTripId);

        if ($tripData === null) {
            return $leg;
        }

        $departure = $this->parseTime($tripData['departure'] ?? $tripData['plannedWhen'] ?? '');
        $arrival = $this->parseTime($tripData['arrival'] ?? $tripData['plannedArrival'] ?? '');

        $update = [
            'actualDeparture' => $departure,
            'actualArrival' => $arrival,
            'departureDelay' => $tripData['departureDelay'] ?? $leg['departureDelay'],
            'arrivalDelay' => $tripData['arrivalDelay'] ?? $leg['arrivalDelay'],
            'departurePlatform' => $tripData['platform'] ?? $tripData['departurePlatform'] ?? $leg['departurePlatform'],
            'arrivalPlatform' => $tripData['arrivalPlatform'] ?? $leg['arrivalPlatform'],
            'cancelled' => $tripData['cancelled'] ?? $leg['cancelled'],
            'status' => $this->deriveLegStatus($tripData),
            'rawResponse' => $tripData,
            'lastCheckedAt' => date('Y-m-d H:i:s.000'),
        ];

        $this->journeyRepo->updateLeg($leg['id'], $update);

        return array_merge($leg, $update);
    }

    public function refreshAllStations(): int
    {
        $count = 0;

        try {
            $response = $this->http->get('https://raw.githubusercontent.com/derhuerst/db-stations/refs/heads/main/data/stations.json', [
                'timeout' => 30,
            ]);

            $stations = json_decode((string) $response->getBody(), true);
            if (!is_array($stations)) {
                return 0;
            }

            $this->stopRepo->deleteAll();
            $now = date('Y-m-d H:i:s.000');

            foreach ($stations as $s) {
                $id = $s['id'] ?? null;
                if ($id === null) {
                    continue;
                }

                $this->stopRepo->upsert((string) $id, [
                    'name' => $s['name'] ?? '',
                    'ril100' => $s['ril100'] ?? null,
                    'latitude' => $s['location']['latitude'] ?? null,
                    'longitude' => $s['location']['longitude'] ?? null,
                    'weight' => $s['weight'] ?? null,
                    'products' => $s['products'] ?? null,
                    'lastUpdated' => $now,
                ]);
                $count++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Station refresh failed: ' . $e->getMessage());
        }

        return $count;
    }

    public function refreshStaleJourneys(int $maxAgeMinutes = 15): int
    {
        $staleLegs = $this->journeyRepo->findStaleLegs($maxAgeMinutes);
        $updated = 0;

        foreach ($staleLegs as $leg) {
            if ($leg['dbTripId'] === null) {
                continue;
            }
            $this->refreshLegFromDb($leg);
            $updated++;
        }

        return $updated;
    }

    private function ensureStopExists(array $stop): void
    {
        $id = $stop['id'] ?? null;
        if ($id === null) {
            return;
        }

        $existing = $this->stopRepo->findById((string) $id);
        if ($existing !== null) {
            return;
        }

        $this->stopRepo->upsert((string) $id, [
            'name' => $stop['name'] ?? '',
            'ril100' => null,
            'latitude' => $stop['location']['latitude'] ?? null,
            'longitude' => $stop['location']['longitude'] ?? null,
            'weight' => null,
            'products' => $stop['products'] ?? null,
            'lastUpdated' => date('Y-m-d H:i:s.000'),
        ]);
    }

    private function mapStopFromDb(array $row): array
    {
        return [
            'id' => $row['id'],
            'type' => 'stop',
            'name' => $row['name'],
            'ril100' => $row['ril100'] ?? null,
            'latitude' => $row['latitude'] ?? null,
            'longitude' => $row['longitude'] ?? null,
            'weight' => $row['weight'] ?? null,
            'products' => $row['products'] ? json_decode($row['products'], true) : null,
        ];
    }

    private function mapJourneyFromApi(array $j): array
    {
        $legs = [];
        foreach ($j['legs'] ?? [] as $leg) {
            $origin = $leg['origin'] ?? [];
            $destination = $leg['destination'] ?? [];
            $line = $leg['line'] ?? null;
            $isWalking = ($leg['walking'] ?? false);

            $legs[] = [
                'origin' => [
                    'id' => (string) ($origin['id'] ?? ''),
                    'name' => $origin['name'] ?? '',
                ],
                'destination' => [
                    'id' => (string) ($destination['id'] ?? ''),
                    'name' => $destination['name'] ?? '',
                ],
                'departure' => $this->normalizeTime($leg['departure'] ?? null),
                'arrival' => $this->normalizeTime($leg['arrival'] ?? null),
                'plannedDeparture' => $this->normalizeTime($leg['plannedWhen'] ?? null),
                'plannedArrival' => $this->normalizeTime($leg['plannedArrival'] ?? null),
                'departureDelay' => $leg['departureDelay'] ?? null,
                'arrivalDelay' => $leg['arrivalDelay'] ?? null,
                'platform' => $leg['platform'] ?? null,
                'plannedPlatform' => $leg['plannedPlatform'] ?? null,
                'walking' => $isWalking,
                'distance' => $leg['distance'] ?? null,
                'mode' => $isWalking ? 'walking' : ($line['product'] ?? 'train'),
                'line' => $line !== null ? [
                    'name' => $line['name'] ?? '',
                    'product' => $line['product'] ?? '',
                ] : null,
                'tripId' => $leg['tripId'] ?? null,
                'cancelled' => $leg['cancelled'] ?? false,
            ];
        }

        return [
            'type' => 'journey',
            'legs' => $legs,
            'transfers' => count(array_filter($legs, fn(array $l) => !($l['walking'] ?? false))) - 1,
        ];
    }

    private function deriveLegStatus(array $leg): string
    {
        if ($leg['cancelled'] ?? false) {
            return 'cancelled';
        }

        $now = time();
        $arrival = $leg['plannedArrival'] ?? $leg['arrival'] ?? null;
        if ($arrival !== null) {
            $arrivalTs = is_string($arrival) ? strtotime($arrival) : $arrival;
            if ($arrivalTs && $arrivalTs < $now) {
                return 'arrived';
            }
        }

        $departure = $leg['plannedDeparture'] ?? $leg['departure'] ?? null;
        if ($departure !== null) {
            $depTs = is_string($departure) ? strtotime($departure) : $departure;
            if ($depTs && $depTs <= $now) {
                return 'in_transit';
            }
        }

        $delay = $leg['departureDelay'] ?? $leg['arrivalDelay'] ?? 0;
        if ($delay > 0) {
            return 'delayed';
        }

        return 'planned';
    }

    private function parseTime(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }

        $ts = strtotime($iso);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function normalizeTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        return $this->parseTime($iso);
    }
}
