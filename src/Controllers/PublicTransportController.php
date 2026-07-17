<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\PublicTransportJourneyRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PublicTransportService;

final readonly class PublicTransportController
{
    private const array ERROR_MAP = [
        'Journey not found' => ['journey_not_found', 404],
        'Not a participant' => ['forbidden', 403],
        'Not a creator' => ['forbidden', 403],
        'from_required' => ['from_required', 400],
        'to_required' => ['to_required', 400],
        'query_required' => ['query_required', 400],
        'journeyData_required' => ['journeyData_required', 400],
    ];

    public function __construct(
        private PublicTransportService $service,
        private PublicTransportJourneyRepository $journeyRepo,
        private TravelRelationRepository $relationRepo,
    ) {}

    public function searchStations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $params = $request->getQueryParams();
        $query = trim((string) ($params['query'] ?? ''));

        if ($query === '') {
            return ResponseFactory::json(['error' => 'query_required'], 400, $response);
        }

        $limit = min(20, max(1, (int) ($params['limit'] ?? 10)));

        try {
            $stops = $this->service->searchStations($query, $limit);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 503, $response);
        }

        return ResponseFactory::json(['data' => $stops], 200, $response);
    }

    public function refreshStations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $count = $this->service->refreshAllStations();
        return ResponseFactory::json(['data' => ['updated' => $count]], 200, $response);
    }

    public function findJourneys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $params = $request->getQueryParams();
        $from = trim((string) ($params['from'] ?? ''));
        $to = trim((string) ($params['to'] ?? ''));

        if ($from === '') {
            return ResponseFactory::json(['error' => 'from_required'], 400, $response);
        }
        if ($to === '') {
            return ResponseFactory::json(['error' => 'to_required'], 400, $response);
        }

        $departure = !empty($params['departure']) ? trim($params['departure']) : null;
        $results = min(10, max(1, (int) ($params['results'] ?? 5)));

        $journeys = $this->service->findJourneys($from, $to, $departure, $results);

        return ResponseFactory::json(['data' => $journeys['data']], 200, $response);
    }

    public function saveJourney(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        $tripId = $body['tripId'] ?? null;
        $journeyData = $body['journeyData'] ?? null;
        $participantIds = $body['participantIds'] ?? [];

        if ($journeyData === null || !is_array($journeyData)) {
            return ResponseFactory::json(['error' => 'journeyData_required'], 400, $response);
        }

        if (!empty($tripId) && !$this->relationRepo->isParticipant($user->id, $tripId)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $journeyId = $this->service->saveJourneyFromApi(
            $user->id,
            $tripId,
            $journeyData,
            is_array($participantIds) ? $participantIds : [],
        );

        return ResponseFactory::json(
            ['data' => $this->getJourneyDetail($journeyId, $user->id)],
            201,
            $response,
        );
    }

    public function listJourneys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $tripId = !empty($params['tripId']) ? trim($params['tripId']) : null;

        $result = $this->journeyRepo->findByUser($user->id, $tripId, $page, $limit);

        $includeLegs = ($params['includeLegs'] ?? 'true') === 'true';
        $result['data'] = array_map(
            fn(array $j) => $this->enrichJourney($j, $includeLegs),
            $result['data'],
        );

        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getJourney(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $detail = $this->getJourneyDetail($args['id'], $user->id);
            return ResponseFactory::json(['data' => $detail], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteJourney(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $journey = $this->journeyRepo->findById($args['id']);
        if ($journey === null) {
            return ResponseFactory::json(['error' => 'journey_not_found'], 404, $response);
        }

        if (!$this->journeyRepo->isCreator($user->id, $args['id'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->journeyRepo->delete($args['id']);
        return ResponseFactory::noContent($response);
    }

    public function addParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->journeyRepo->isParticipant($user->id, $args['id'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || empty($body['userId'])) {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        $userId = trim($body['userId']);
        $this->journeyRepo->addParticipant($args['id'], $userId);

        return ResponseFactory::json(
            ['data' => $this->journeyRepo->getParticipants($args['id'])],
            200,
            $response,
        );
    }

    public function removeParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->journeyRepo->isParticipant($user->id, $args['id'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->journeyRepo->removeParticipant($args['id'], $args['userId']);

        return ResponseFactory::json(
            ['data' => $this->journeyRepo->getParticipants($args['id'])],
            200,
            $response,
        );
    }

    public function refreshJourney(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->journeyRepo->isParticipant($user->id, $args['id'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $legs = $this->journeyRepo->getLegs($args['id']);
        foreach ($legs as $leg) {
            if ($leg['dbTripId'] !== null && $leg['status'] !== 'arrived') {
                $this->service->refreshLegFromDb($leg);
            }
        }

        return ResponseFactory::json(
            ['data' => $this->getJourneyDetail($args['id'], $user->id)],
            200,
            $response,
        );
    }

    public function refreshAllJourneys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();
        $maxAgeMinutes = is_array($body) ? (int) ($body['maxAgeMinutes'] ?? 15) : 15;

        $updated = $this->service->refreshStaleJourneys($maxAgeMinutes);

        return ResponseFactory::json(['data' => ['updated' => $updated]], 200, $response);
    }

    private function getJourneyDetail(string $journeyId, string $userId): array
    {
        $journey = $this->journeyRepo->findById($journeyId);
        if ($journey === null) {
            throw new \RuntimeException('Journey not found');
        }

        if (!$this->journeyRepo->isParticipant($userId, $journeyId)) {
            throw new \RuntimeException('Not a participant');
        }

        return $this->enrichJourney($journey, true);
    }

    private function enrichJourney(array $journey, bool $includeLegs = true): array
    {
        $result = [
            'id' => $journey['id'],
            'tripId' => $journey['tripId'] ?? null,
            'creatorId' => $journey['creatorId'],
            'chosenAt' => $journey['chosenAt'],
            'createdAt' => $journey['createdAt'],
        ];

        if ($includeLegs) {
            $result['legs'] = $this->journeyRepo->getLegs($journey['id']);
        }

        $result['participants'] = $this->journeyRepo->getParticipants($journey['id']);

        return $result;
    }

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
