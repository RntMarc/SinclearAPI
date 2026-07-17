<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Helpers\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PtService;

final readonly class PtController
{
    private const array ERROR_MAP = [
        'Not a participant'    => ['not_participant', 403],
        'Not the creator'      => ['not_creator', 403],
        'Journey not found'    => ['journey_not_found', 404],
    ];

    public function __construct(
        private PtService $ptService,
    ) {}

    // =========================================================================
    // Stations
    // =========================================================================

    public function searchStations(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $query = $params['q'] ?? $params['query'] ?? '';
        $limit = min(20, max(1, (int) ($params['limit'] ?? 10)));

        if ($query === '') {
            return ResponseFactory::json(['error' => 'query_required'], 400, $response);
        }

        $stations = $this->ptService->searchStationsWithCache($query, $limit);

        return ResponseFactory::json(['data' => $stations], 200, $response);
    }

    public function getDepartures(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $limit = min(50, max(1, (int) ($params['limit'] ?? 10)));
        $arriveBy = filter_var($params['arriveBy'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $departures = $this->ptService->getDepartures($args['id'], $limit, $arriveBy);

        return ResponseFactory::json(['data' => $departures], 200, $response);
    }

    // =========================================================================
    // Journey Search
    // =========================================================================

    public function findJourneys(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $departure = $params['departure'] ?? null;
        $arriveBy = filter_var($params['arriveBy'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $results = min(10, max(1, (int) ($params['results'] ?? 5)));
        $maxTransfers = isset($params['maxTransfers']) ? (int) $params['maxTransfers'] : null;
        $pageCursor = $params['pageCursor'] ?? null;

        if ($from === null || $to === null) {
            return ResponseFactory::json(['error' => 'from_and_to_required'], 400, $response);
        }

        // Convert UTC datetime to ISO 8601 if provided
        if ($departure !== null) {
            $departure = str_replace(' ', 'T', $departure) . 'Z';
        }

        $journeys = $this->ptService->searchJourneys(
            $from,
            $to,
            $departure,
            $arriveBy,
            $results,
            $maxTransfers,
            $pageCursor,
        );

        return ResponseFactory::json(['data' => $journeys], 200, $response);
    }

    // =========================================================================
    // Saved Journeys CRUD
    // =========================================================================

    public function saveJourney(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $data = (array) $request->getParsedBody();

        if (empty($data['legs'])) {
            return ResponseFactory::json(['error' => 'legs_required'], 400, $response);
        }

        $participantIds = $data['participantIds'] ?? [];

        $journeyData = [
            'creatorId' => $user->id,
            'tripId' => $data['tripId'] ?? null,
            'fromStationId' => $data['fromStationId'] ?? '',
            'fromStationName' => $data['fromStationName'] ?? '',
            'toStationId' => $data['toStationId'] ?? '',
            'toStationName' => $data['toStationName'] ?? '',
            'departureTime' => $data['departureTime'] ?? '',
            'arrivalTime' => $data['arrivalTime'] ?? '',
            'duration' => $data['duration'] ?? 0,
            'transfers' => $data['transfers'] ?? 0,
            'legs' => $data['legs'],
        ];

        try {
            $journey = $this->ptService->saveJourney($journeyData, $participantIds);
            return ResponseFactory::json(['data' => $journey], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }
    }

    public function listJourneys(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $tripId = $params['tripId'] ?? null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->ptService->listJourneys($user->id, $tripId, $page, $limit);

        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getJourney(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        try {
            $journey = $this->ptService->getJourney($args['id'], $user->id);
            if ($journey === null) {
                return ResponseFactory::json(['error' => 'journey_not_found'], 404, $response);
            }
            return ResponseFactory::json(['data' => $journey], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteJourney(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        try {
            $this->ptService->deleteJourney($args['id'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // =========================================================================
    // Refresh
    // =========================================================================

    public function refreshJourney(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        try {
            $journey = $this->ptService->refreshJourney($args['id'], $user->id);
            return ResponseFactory::json(['data' => $journey], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // =========================================================================
    // Participants
    // =========================================================================

    public function addParticipant(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $data = (array) $request->getParsedBody();
        $userId = $data['userId'] ?? null;

        if ($userId === null) {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        try {
            $participants = $this->ptService->addParticipant($args['id'], $user->id, $userId);
            return ResponseFactory::json(['data' => $participants], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeParticipant(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        try {
            $participants = $this->ptService->removeParticipant($args['id'], $user->id, $args['userId']);
            return ResponseFactory::json(['data' => $participants], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function errorResponse(\RuntimeException $e, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$e->getMessage()] ?? ['internal_error', 500];
        return ResponseFactory::json(['error' => $mapped[0]], $mapped[1], $response);
    }
}
