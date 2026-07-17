<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\PublicTransportJourneyRepository;
use Sinclear\Api\Repository\TravelStopRepository;

final readonly class PublicTransportService
{
    private const API_BASE = 'https://api.transitous.org';
    private const USER_AGENT = 'SinclearBeyond/1.0 (dev@sinclear.com)';

    public function __construct(
        private Client $http,
        private PublicTransportJourneyRepository $journeyRepo,
        private TravelStopRepository $stopRepo,
        private LoggerInterface $logger,
    ) {}

    private function apiOpts(array $overrides = []): array
    {
        return array_merge([
            'headers' => ['User-Agent' => self::USER_AGENT],
        ], $overrides);
    }

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
            $response = $this->http->get(self::API_BASE . '/api/v1/geocode', $this->apiOpts([
                'query' => [
                    'text' => $query,
                    'type' => 'STOP',
                    'numResults' => $limit,
                ],
            ]));

            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body)) {
                return [];
            }

            $now = date('Y-m-d H:i:s.000');
            foreach ($body as $item) {
                $id = $item['id'] ?? null;
                if ($id === null || ($item['type'] ?? '') !== 'STOP') {
                    continue;
                }
                $this->stopRepo->upsert($id, [
                    'name' => $item['name'] ?? '',
                    'ril100' => null,
                    'latitude' => $item['lat'] ?? null,
                    'longitude' => $item['lon'] ?? null,
                    'weight' => $item['importance'] ?? null,
                    'products' => isset($item['modes']) ? json_encode($item['modes']) : null,
                    'lastUpdated' => $now,
                ]);
            }

            $local = $this->stopRepo->searchByNameFuzzy($query, $limit);
            return array_map(fn(array $s) => $this->mapStopFromDb($s), $local);
        } catch (\Throwable $e) {
            $this->logger->warning('Station search failed: ' . $e->getMessage());
            throw new \RuntimeException('Stationen-Datenbank leer und API nicht erreichbar. Bitte zuerst Stationen aktualisieren via POST /api/v2/public-transport/stations/refresh');
        }
    }

    /** @return array{data: list<array>, refreshToken: null} */
    public function findJourneys(
        string $fromId,
        string $toId,
        ?string $departure,
        int $results = 5,
    ): array {
        $query = [
            'fromPlace' => $fromId,
            'toPlace' => $toId,
            'numItineraries' => $results,
        ];

        if ($departure !== null) {
            $query['time'] = str_replace(' ', 'T', $departure) . 'Z';
        }

        $lastException = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(500_000 * $attempt);
            }
            try {
                $response = $this->http->get(self::API_BASE . '/api/v6/plan', $this->apiOpts([
                    'query' => $query,
                ]));

                $body = json_decode((string) $response->getBody(), true);
                if (!is_array($body) || !isset($body['itineraries'])) {
                    return ['data' => [], 'refreshToken' => null];
                }

                $journeys = [];
                foreach ($body['itineraries'] as $j) {
                    $journeys[] = $this->mapJourneyFromApi($j);
                }

                return [
                    'data' => $journeys,
                    'refreshToken' => null,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($e instanceof \GuzzleHttp\Exception\ServerException) {
                    continue;
                }
                break;
            }
        }

        $this->logger->warning('Journey search failed: ' . $lastException->getMessage());
        return ['data' => [], 'refreshToken' => null];
    }

    /** @return array{legs: list<array>}|null */
    public function refreshTrip(string $dbTripId): ?array
    {
        $lastException = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(500_000 * $attempt);
            }
            try {
                $response = $this->http->get(self::API_BASE . '/api/v6/trip', $this->apiOpts([
                    'query' => ['tripId' => $dbTripId],
                ]));
                return json_decode((string) $response->getBody(), true);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($e instanceof \GuzzleHttp\Exception\ServerException) {
                    continue;
                }
                break;
            }
        }
        $this->logger->warning("Trip refresh failed for $dbTripId: " . $lastException->getMessage());
        return null;
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

        $motisLeg = $tripData['legs'][0] ?? [];
        $from = $motisLeg['from'] ?? [];
        $to = $motisLeg['to'] ?? [];

        $departure = $this->normalizeTime($from['departure'] ?? null);
        $arrival = $this->normalizeTime($to['arrival'] ?? null);

        $mapped = [
            'departure' => $from['departure'] ?? null,
            'plannedWhen' => $from['scheduledDeparture'] ?? null,
            'plannedDeparture' => $from['scheduledDeparture'] ?? null,
            'arrival' => $to['arrival'] ?? null,
            'plannedArrival' => $to['scheduledArrival'] ?? null,
            'departureDelay' => $this->calcDelay(
                $from['scheduledDeparture'] ?? null,
                $from['departure'] ?? null,
                $motisLeg['realTime'] ?? false,
            ),
            'arrivalDelay' => $this->calcDelay(
                $to['scheduledArrival'] ?? null,
                $to['arrival'] ?? null,
                $motisLeg['realTime'] ?? false,
            ),
            'platform' => $from['track'] ?? null,
            'departurePlatform' => $from['track'] ?? null,
            'arrivalPlatform' => $to['track'] ?? null,
            'cancelled' => $motisLeg['cancelled'] ?? false,
        ];

        $update = [
            'actualDeparture' => $departure,
            'actualArrival' => $arrival,
            'departureDelay' => $mapped['departureDelay'] ?? $leg['departureDelay'],
            'arrivalDelay' => $mapped['arrivalDelay'] ?? $leg['arrivalDelay'],
            'departurePlatform' => $mapped['departurePlatform'] ?? $leg['departurePlatform'],
            'arrivalPlatform' => $mapped['arrivalPlatform'] ?? $leg['arrivalPlatform'],
            'cancelled' => $mapped['cancelled'] ?? $leg['cancelled'],
            'status' => $this->deriveLegStatus($mapped),
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
            $response = $this->http->get('https://unpkg.com/db-stations@5.0.2/data.ndjson', [
                'timeout' => 60,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $now = date('Y-m-d H:i:s.000');

            $this->stopRepo->deleteAll();

            foreach ($this->readNdjson($body) as $s) {
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

    private function readNdjson($stream): iterable
    {
        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
        if (trim($buffer) !== '') {
            $decoded = json_decode(trim($buffer), true);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
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
        $id = $stop['id'] ?? $stop['stopId'] ?? null;
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
            'latitude' => $stop['location']['latitude'] ?? $stop['lat'] ?? null,
            'longitude' => $stop['location']['longitude'] ?? $stop['lon'] ?? null,
            'weight' => null,
            'products' => $stop['products'] ?? $stop['modes'] ?? null,
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

    private function mapJourneyFromApi(array $motisItinerary): array
    {
        $legs = [];
        foreach ($motisItinerary['legs'] ?? [] as $leg) {
            $from = $leg['from'] ?? [];
            $to = $leg['to'] ?? [];
            $mode = $leg['mode'] ?? 'RAIL';
            $isWalking = $mode === 'WALK';

            $legs[] = [
                'origin' => [
                    'id' => (string) ($from['stopId'] ?? ''),
                    'name' => $from['name'] ?? '',
                    'location' => [
                        'latitude' => $from['lat'] ?? null,
                        'longitude' => $from['lon'] ?? null,
                    ],
                    'products' => $from['modes'] ?? null,
                ],
                'destination' => [
                    'id' => (string) ($to['stopId'] ?? ''),
                    'name' => $to['name'] ?? '',
                    'location' => [
                        'latitude' => $to['lat'] ?? null,
                        'longitude' => $to['lon'] ?? null,
                    ],
                    'products' => $to['modes'] ?? null,
                ],
                'departure' => $this->normalizeTime($from['departure'] ?? null),
                'arrival' => $this->normalizeTime($to['arrival'] ?? null),
                'plannedWhen' => $this->normalizeTime($from['scheduledDeparture'] ?? null),
                'plannedArrival' => $this->normalizeTime($to['scheduledArrival'] ?? null),
                'departureDelay' => $this->calcDelay(
                    $from['scheduledDeparture'] ?? null,
                    $from['departure'] ?? null,
                    $leg['realTime'] ?? false,
                ),
                'arrivalDelay' => $this->calcDelay(
                    $to['scheduledArrival'] ?? null,
                    $to['arrival'] ?? null,
                    $leg['realTime'] ?? false,
                ),
                'platform' => $from['track'] ?? null,
                'plannedPlatform' => $from['scheduledTrack'] ?? null,
                'walking' => $isWalking,
                'distance' => $leg['distance'] ?? null,
                'mode' => $isWalking ? 'walking' : strtolower($mode),
                'line' => isset($leg['routeShortName']) ? [
                    'name' => $leg['routeShortName'],
                    'product' => strtolower($mode),
                ] : null,
                'tripId' => $leg['tripId'] ?? null,
                'cancelled' => $leg['cancelled'] ?? false,
            ];
        }

        return [
            'type' => 'journey',
            'legs' => $legs,
            'transfers' => max(0, $motisItinerary['transfers'] ?? 0),
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

    private function calcDelay(?string $scheduled, ?string $actual, bool $realTime): ?int
    {
        if (!$realTime || $scheduled === null || $actual === null) {
            return null;
        }
        $scheduledTs = strtotime($scheduled);
        $actualTs = strtotime($actual);
        if ($scheduledTs === false || $actualTs === false) {
            return null;
        }
        return $actualTs - $scheduledTs;
    }
}
