<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Sinclear\Api\Repository\PtStationRepository;
use Sinclear\Api\Repository\PtJourneyRepository;

final readonly class PtService
{
    private Client $httpClient;

    private const string TRANSITIOUS_BASE = 'https://api.transitous.org/api';
    private const int HTTP_TIMEOUT = 15;
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';

    public function __construct(
        private PtStationRepository $stationRepo,
        private PtJourneyRepository $journeyRepo,
    ) {
        $this->httpClient = new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);
    }

    // =========================================================================
    // Station Search (Geocoding)
    // =========================================================================

    /**
     * Search stations via Transitious Geocoding API
     *
     * @return list<array<string, mixed>>
     */
    public function searchStations(string $query, int $limit = 10): array
    {
        $response = $this->transitiousRequest('GET', '/v1/geocode', [
            'query' => [
                'text' => $query,
                'language' => ['de'],
                'numResults' => $limit,
            ],
        ]);

        $data = $this->parseResponse($response);
        $results = [];

        if (is_array($data)) {
            foreach ($data as $item) {
                $results[] = $this->mapStation($item);
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Search stations with local cache fallback
     *
     * @return list<array<string, mixed>>
     */
    public function searchStationsWithCache(string $query, int $limit = 10): array
    {
        // Try local cache first
        $cached = $this->stationRepo->search($query, $limit);
        if (!empty($cached)) {
            return array_map(fn(array $s) => $this->formatStation($s), $cached);
        }

        // Fallback to Transitious API
        $results = $this->searchStations($query, $limit);

        // Cache results locally
        foreach ($results as $station) {
            $this->stationRepo->upsert($station);
        }

        return $results;
    }

    // =========================================================================
    // Departures
    // =========================================================================

    /**
     * Get departures/arrivals for a station
     *
     * @return list<array<string, mixed>>
     */
    public function getDepartures(string $stopId, int $limit = 10, bool $arriveBy = false): array
    {
        $response = $this->transitiousRequest('GET', '/v6/stoptimes', [
            'query' => [
                'stopId' => $stopId,
                'n' => $limit,
                'arriveBy' => $arriveBy,
            ],
        ]);

        $data = $this->parseResponse($response);
        $results = [];

        if (isset($data['stopTimes']) && is_array($data['stopTimes'])) {
            foreach ($data['stopTimes'] as $stopTime) {
                $results[] = $this->mapDeparture($stopTime);
            }
        }

        return $results;
    }

    // =========================================================================
    // Journey Search (Routing)
    // =========================================================================

    /**
     * Search journeys from A to B
     *
     * @return list<array<string, mixed>>
     */
    public function searchJourneys(
        string $fromPlace,
        string $toPlace,
        ?string $departure = null,
        bool $arriveBy = false,
        int $numItineraries = 5,
        ?int $maxTransfers = null,
        ?string $pageCursor = null,
    ): array {
        $params = [
            'fromPlace' => $fromPlace,
            'toPlace' => $toPlace,
            'numItineraries' => $numItineraries,
            'arriveBy' => $arriveBy,
            'language' => ['de'],
        ];

        if ($departure !== null) {
            $params['time'] = $departure;
        }

        if ($maxTransfers !== null) {
            $params['maxTransfers'] = $maxTransfers;
        }

        if ($pageCursor !== null) {
            $params['pageCursor'] = $pageCursor;
        }

        $response = $this->transitiousRequest('GET', '/v6/plan', [
            'query' => $params,
        ]);

        $data = $this->parseResponse($response);
        $results = [];

        if (isset($data['itineraries']) && is_array($data['itineraries'])) {
            foreach ($data['itineraries'] as $itinerary) {
                $results[] = $this->mapItinerary($itinerary);
            }
        }

        return $results;
    }

    // =========================================================================
    // Trip Details (for refresh)
    // =========================================================================

    /**
     * Get trip details for refresh
     */
    public function getTripDetails(string $tripId, string $time): ?array
    {
        $response = $this->transitiousRequest('GET', '/v6/trip', [
            'query' => [
                'tripId' => $tripId,
            ],
        ]);

        $data = $this->parseResponse($response);

        return $data ?: null;
    }

    // =========================================================================
    // Saved Journeys CRUD
    // =========================================================================

    /**
     * Save a journey
     */
    public function saveJourney(array $data, array $participantIds = []): array
    {
        $journey = $this->journeyRepo->create($data, $data['legs'] ?? [], $participantIds);

        return $this->formatJourney($journey);
    }

    /**
     * List user's saved journeys
     */
    public function listJourneys(string $userId, ?string $tripId = null, int $page = 1, int $limit = 20): array
    {
        $result = $this->journeyRepo->listByUser($userId, $tripId, $page, $limit);
        $result['data'] = array_map(fn(array $j) => $this->formatJourney($j), $result['data']);

        return $result;
    }

    /**
     * Get journey details
     */
    public function getJourney(string $id, string $userId): ?array
    {
        $journey = $this->journeyRepo->findById($id);
        if ($journey === null) {
            return null;
        }

        // Check membership
        if (!$this->journeyRepo->isParticipant($id, $userId)) {
            throw new \RuntimeException('Not a participant');
        }

        return $this->formatJourneyFull($journey);
    }

    /**
     * Delete a journey (creator only)
     */
    public function deleteJourney(string $id, string $userId): void
    {
        $journey = $this->journeyRepo->findById($id);
        if ($journey === null) {
            throw new \RuntimeException('Journey not found');
        }

        if (!$this->journeyRepo->isCreator($id, $userId)) {
            throw new \RuntimeException('Not the creator');
        }

        $this->journeyRepo->delete($id);
    }

    /**
     * Refresh a single journey's legs
     */
    public function refreshJourney(string $id, string $userId): array
    {
        $journey = $this->journeyRepo->findById($id);
        if ($journey === null) {
            throw new \RuntimeException('Journey not found');
        }

        if (!$this->journeyRepo->isParticipant($id, $userId)) {
            throw new \RuntimeException('Not a participant');
        }

        $legs = $this->journeyRepo->findLegsByJourney($id);
        $refreshedCount = 0;

        foreach ($legs as $leg) {
            if ($leg['tripId'] === null) {
                continue;
            }

            $tripData = $this->getTripDetails($leg['tripId'], $leg['plannedDeparture']);
            if ($tripData !== null) {
                $this->updateLegFromTrip($leg['id'], $tripData);
                $refreshedCount++;
            }
        }

        return $this->formatJourneyFull($this->journeyRepo->findById($id));
    }

    /**
     * Add participant to journey
     */
    public function addParticipant(string $journeyId, string $userId, string $newParticipantId): array
    {
        $journey = $this->journeyRepo->findById($journeyId);
        if ($journey === null) {
            throw new \RuntimeException('Journey not found');
        }

        if (!$this->journeyRepo->isParticipant($journeyId, $userId)) {
            throw new \RuntimeException('Not a participant');
        }

        $this->journeyRepo->addParticipant($journeyId, $newParticipantId);

        return $this->journeyRepo->findParticipantsByJourney($journeyId);
    }

    /**
     * Remove participant from journey
     */
    public function removeParticipant(string $journeyId, string $userId, string $participantId): array
    {
        $journey = $this->journeyRepo->findById($journeyId);
        if ($journey === null) {
            throw new \RuntimeException('Journey not found');
        }

        if (!$this->journeyRepo->isParticipant($journeyId, $userId)) {
            throw new \RuntimeException('Not a participant');
        }

        $this->journeyRepo->removeParticipant($journeyId, $participantId);

        return $this->journeyRepo->findParticipantsByJourney($journeyId);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function transitiousRequest(string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            return $this->httpClient->request($method, self::TRANSITIOUS_BASE . $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Transitious API request failed: ' . $e->getMessage());
        }
    }

    private function parseResponse(ResponseInterface $response): mixed
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from Transitious API');
        }

        return $data;
    }

    private function mapStation(array $item): array
    {
        return [
            'id' => $item['id'] ?? $item['gtfsId'] ?? '',
            'name' => $item['name'] ?? '',
            'latitude' => $item['lat'] ?? null,
            'longitude' => $item['lon'] ?? null,
        ];
    }

    private function formatStation(array $station): array
    {
        return [
            'id' => $station['id'],
            'name' => $station['name'],
            'latitude' => $station['latitude'] !== null ? (float) $station['latitude'] : null,
            'longitude' => $station['longitude'] !== null ? (float) $station['longitude'] : null,
        ];
    }

    private function mapDeparture(array $stopTime): array
    {
        $trip = $stopTime['trip'] ?? [];
        $stop = $stopTime['stop'] ?? [];

        return [
            'lineName' => $trip['label'] ?? null,
            'mode' => $trip['mode'] ?? null,
            'departure' => $this->formatTransitTime($stopTime['time'] ?? null),
            'platform' => $stop['platform'] ?? null,
            'headsign' => $trip['headsign'] ?? null,
        ];
    }

    private function mapItinerary(array $itinerary): array
    {
        $legs = [];
        if (isset($itinerary['legs']) && is_array($itinerary['legs'])) {
            foreach ($itinerary['legs'] as $leg) {
                $legs[] = $this->mapLeg($leg);
            }
        }

        return [
            'duration' => $itinerary['duration'] ?? 0,
            'transfers' => $itinerary['transfers'] ?? 0,
            'departureTime' => $this->formatTransitTime($itinerary['startTime'] ?? null),
            'arrivalTime' => $this->formatTransitTime($itinerary['endTime'] ?? null),
            'legs' => $legs,
        ];
    }

    private function mapLeg(array $leg): array
    {
        $from = $leg['from'] ?? [];
        $to = $leg['to'] ?? [];
        $trip = $leg['trip'] ?? null;

        return [
            'mode' => $leg['mode'] ?? 'WALK',
            'lineName' => $trip['routeShortName'] ?? $trip['label'] ?? null,
            'lineProduct' => $trip['routeType'] ?? null,
            'fromStationId' => $from['id'] ?? null,
            'fromStationName' => $from['name'] ?? null,
            'toStationId' => $to['id'] ?? null,
            'toStationName' => $to['name'] ?? null,
            'tripId' => $trip['tripId'] ?? null,
            'plannedDeparture' => $this->formatTransitTime($leg['startTime'] ?? null),
            'plannedArrival' => $this->formatTransitTime($leg['endTime'] ?? null),
            'departureDelay' => $leg['departureDelay'] ?? null,
            'arrivalDelay' => $leg['arrivalDelay'] ?? null,
            'departurePlatform' => $leg['from']['platform'] ?? null,
            'arrivalPlatform' => $leg['to']['platform'] ?? null,
            'cancelled' => ($leg['realTimeState'] ?? '') === 'CANCELED',
            'realTimeState' => $leg['realTimeState'] ?? null,
        ];
    }

    private function formatTransitTime(?string $isoTime): ?string
    {
        if ($isoTime === null) {
            return null;
        }

        // Convert ISO 8601 to UTC datetime format
        $dt = new \DateTime($isoTime, new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private function updateLegFromTrip(string $legId, array $tripData): void
    {
        $update = [
            'rawResponse' => json_encode($tripData),
            'lastCheckedAt' => date('Y-m-d H:i:s.') . substr((string) microtime(true), strpos((string) microtime(true), '.') + 1, 3),
        ];

        // Extract real-time data from trip response if available
        if (isset($tripData['realtimeState'])) {
            $update['realTimeState'] = $tripData['realtimeState'];
        }

        $this->journeyRepo->updateLeg($legId, $update);
    }

    private function formatJourney(array $journey): array
    {
        return [
            'id' => $journey['id'],
            'tripId' => $journey['tripId'],
            'creatorId' => $journey['creatorId'],
            'fromStationId' => $journey['fromStationId'],
            'fromStationName' => $journey['fromStationName'],
            'toStationId' => $journey['toStationId'],
            'toStationName' => $journey['toStationName'],
            'departureTime' => $journey['departureTime'],
            'arrivalTime' => $journey['arrivalTime'],
            'duration' => (int) $journey['duration'],
            'transfers' => (int) $journey['transfers'],
            'chosenAt' => $journey['chosenAt'],
            'createdAt' => $journey['createdAt'],
        ];
    }

    private function formatJourneyFull(array $journey): array
    {
        $formatted = $this->formatJourney($journey);
        $formatted['legs'] = array_map(
            fn(array $leg) => $this->formatLeg($leg),
            $this->journeyRepo->findLegsByJourney($journey['id'])
        );
        $formatted['participants'] = $this->journeyRepo->findParticipantsByJourney($journey['id']);

        return $formatted;
    }

    private function formatLeg(array $leg): array
    {
        return [
            'id' => $leg['id'],
            'journeyId' => $leg['journeyId'],
            'legIndex' => (int) $leg['legIndex'],
            'mode' => $leg['mode'],
            'lineName' => $leg['lineName'],
            'lineProduct' => $leg['lineProduct'],
            'fromStationId' => $leg['fromStationId'],
            'fromStationName' => $leg['fromStationName'],
            'toStationId' => $leg['toStationId'],
            'toStationName' => $leg['toStationName'],
            'tripId' => $leg['tripId'],
            'plannedDeparture' => $leg['plannedDeparture'],
            'plannedArrival' => $leg['plannedArrival'],
            'actualDeparture' => $leg['actualDeparture'],
            'actualArrival' => $leg['actualArrival'],
            'departureDelay' => $leg['departureDelay'] !== null ? (int) $leg['departureDelay'] : null,
            'arrivalDelay' => $leg['arrivalDelay'] !== null ? (int) $leg['arrivalDelay'] : null,
            'departurePlatform' => $leg['departurePlatform'],
            'arrivalPlatform' => $leg['arrivalPlatform'],
            'cancelled' => (bool) $leg['cancelled'],
            'realTimeState' => $leg['realTimeState'],
        ];
    }
}
