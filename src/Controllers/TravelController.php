<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\TravelService;

final readonly class TravelController
{
    private const array ERROR_MAP = [
        'Not a participant' => ['forbidden', 403],
        'Trip not found' => ['trip_not_found', 404],
        'Event not found' => ['event_not_found', 404],
        'Accommodation not found' => ['accommodation_not_found', 404],
    ];

    public function __construct(
        private TravelService $travelService,
    ) {}

    public function listTrips(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->travelService->listTrips($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->getTrip($args['id'], $user->id);
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listStandaloneEvents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->travelService->listStandaloneEvents($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getStandaloneEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->getStandaloneEvent($args['eventId'], $user->id);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listEvents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $events = $this->travelService->listEvents($args['id'], $user->id);
            return ResponseFactory::json(['data' => $events], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->getEvent($args['id'], $args['eventId'], $user->id);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listAccommodations(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodations = $this->travelService->listAccommodations($args['id'], $user->id);
            return ResponseFactory::json(['data' => $accommodations], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->getAccommodation($args['id'], $args['accommodationId'], $user->id);
            return ResponseFactory::json(['data' => $accommodation], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listParticipants(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $participants = $this->travelService->listParticipants($args['id'], $user->id);
            return ResponseFactory::json(['data' => $participants], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
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
