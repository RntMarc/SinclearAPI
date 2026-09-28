<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\TravelService;
use Sinclear\Api\Support\TravelError;

final readonly class TravelController
{
    public function __construct(
        private TravelService $travelService,
        private LoggerInterface $logger,
    ) {}

    // ──────────────────────────── Lesen ────────────────────────────

    public function listTrips(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->travelService->listTrips($user, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->getTrip($args['id'], $user);
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

        $result = $this->travelService->listStandaloneEvents($user, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function getStandaloneEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->getStandaloneEvent($args['eventId'], $user);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listEvents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $events = $this->travelService->listEvents($args['id'], $user);
            return ResponseFactory::json(['data' => $events], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->getEvent($args['id'], $args['eventId'], $user);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listAccommodations(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodations = $this->travelService->listAccommodations($args['id'], $user);
            return ResponseFactory::json(['data' => $accommodations], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->getAccommodation($args['id'], $args['accommodationId'], $user);
            return ResponseFactory::json(['data' => $accommodation], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getEventById(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->getEventById($args['eventId'], $user);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getTripSubscriptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $subscriptions = $this->travelService->getTripSubscriptions($args['id'], $user);
            return ResponseFactory::json(['data' => $subscriptions], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listTripTickets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $tickets = $this->travelService->listTripTickets($args['id'], $user);
            return ResponseFactory::json(['data' => $tickets], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listEventTickets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $tickets = $this->travelService->listEventTickets($args['eventId'], $user);
            return ResponseFactory::json(['data' => $tickets], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listUserTickets(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tickets = $this->travelService->listUserTickets($user->id);
        return ResponseFactory::json(['data' => $tickets], 200, $response);
    }

    public function createUserTicket(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        try {
            $ticket = $this->travelService->createUserTicket($user->id, [
                'title' => isset($body['title']) && is_string($body['title'])
                    ? trim($body['title']) : null,
                'qrcode' => isset($body['qrcode']) && is_string($body['qrcode'])
                    ? trim($body['qrcode']) : null,
                'image' => isset($body['image']) && is_string($body['image'])
                    ? trim($body['image']) : null,
                'event' => isset($body['event']) && is_string($body['event'])
                    ? trim($body['event']) : null,
                'trip' => isset($body['trip']) && is_string($body['trip'])
                    ? trim($body['trip']) : null,
            ]);

            return ResponseFactory::json(['data' => $ticket], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateUserTicket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();
        $data = [];

        if (isset($body['title'])) {
            $data['title'] = is_string($body['title']) ? trim($body['title']) : null;
        }
        if (isset($body['qrcode'])) {
            $data['qrcode'] = is_string($body['qrcode']) ? trim($body['qrcode']) : null;
        }
        if (isset($body['image'])) {
            $data['image'] = is_string($body['image']) ? trim($body['image']) : null;
        }
        if (array_key_exists('event', $body)) {
            $data['event'] = $body['event'] !== null && is_string($body['event'])
                ? trim($body['event']) : null;
        }
        if (array_key_exists('trip', $body)) {
            $data['trip'] = $body['trip'] !== null && is_string($body['trip'])
                ? trim($body['trip']) : null;
        }

        try {
            $ticket = $this->travelService->updateUserTicket($args['ticketId'], $user->id, $data);
            return ResponseFactory::json(['data' => $ticket], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteUserTicket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteUserTicket($args['ticketId'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function listParticipants(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $participants = $this->travelService->listParticipants($args['id'], $user);
            return ResponseFactory::json(['data' => $participants], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Reisen schreiben ────────────────────────────

    public function createTrip(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->createTrip($user, $this->body($request));
            return ResponseFactory::json(['data' => $trip], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->updateTrip($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteTrip($user, $args['id']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Reise-Events schreiben ────────────────────────────

    public function createTripEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->createTripEvent($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $event], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateTripEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->updateTripEvent($user, $args['id'], $args['eventId'], $this->body($request));
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteTripEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteTripEvent($user, $args['id'], $args['eventId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Standalone-Events schreiben ────────────────────────────

    public function createStandaloneEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->createStandaloneEvent($user, $this->body($request));
            return ResponseFactory::json(['data' => $event], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateStandaloneEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->travelService->updateStandaloneEvent($user, $args['eventId'], $this->body($request));
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteStandaloneEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteStandaloneEvent($user, $args['eventId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Teilnehmer & Rollen (Reise) ────────────────────────────

    public function addTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);

        $targetUserId = trim((string) ($body['userId'] ?? ''));
        if ($targetUserId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        $accommodation = isset($body['accommodation']) && is_string($body['accommodation']) && $body['accommodation'] !== ''
            ? trim($body['accommodation']) : null;

        try {
            $relationId = $this->travelService->addParticipant($user, $args['id'], $targetUserId, $accommodation);
            return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->removeParticipant($user, $args['id'], $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setTripParticipantRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $role = trim((string) ($body['role'] ?? ''));

        try {
            $this->travelService->setParticipantRole($user, $args['id'], $args['userId'], $role);
            return ResponseFactory::json(['message' => 'role_updated'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Teilnehmer & Rollen (Standalone-Event) ────────────────────────────

    public function addStandaloneEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);

        $targetUserId = trim((string) ($body['userId'] ?? ''));
        if ($targetUserId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        try {
            $relationId = $this->travelService->addEventParticipant($user, $args['eventId'], $targetUserId);
            return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeStandaloneEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->removeEventParticipant($user, $args['eventId'], $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setStandaloneEventParticipantRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $role = trim((string) ($body['role'] ?? ''));

        try {
            $this->travelService->setStandaloneEventParticipantRole($user, $args['eventId'], $args['userId'], $role);
            return ResponseFactory::json(['message' => 'role_updated'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Unterkünfte schreiben ────────────────────────────

    public function createAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->createAccommodation($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $accommodation], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->updateAccommodation($user, $args['id'], $args['accommodationId'], $this->body($request));
            return ResponseFactory::json(['data' => $accommodation], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteAccommodation($user, $args['id'], $args['accommodationId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Helfer ────────────────────────────

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
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
        [$code, $status] = TravelError::resolve($e->getMessage());

        if ($status >= 500) {
            $this->logger->error('Travel request failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return ResponseFactory::json(['error' => $code], $status, $response);
    }
}
