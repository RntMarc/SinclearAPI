<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\TravelPlanningService;
use Sinclear\Api\Support\TravelPlanningError;

/**
 * Endpunkte der Reiseplanung. Alle Routen liegen unter /trips/planning und
 * benoetigen einen gueltigen JWT. Die Berechtigungspruefung erfolgt im
 * TravelPlanningService (Policy); der Controller normalisiert nur die
 * HTTP-Eingaben.
 */
final readonly class TravelPlanningController
{
    public function __construct(
        private TravelPlanningService $planningService,
        private LoggerInterface $logger,
    ) {}

    // ──────────────────────────── Reisen ────────────────────────────

    public function listTrips(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->planningService->listPlanningTrips($user, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function createTrip(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->planningService->createPlanningTrip($user, $this->body($request));
            return ResponseFactory::json(['data' => $trip], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function getTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->planningService->getPlan($args['id'], $user);
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->planningService->updatePlanningTrip($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function activateTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->planningService->activatePlanningTrip($user, $args['id']);
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Mitglieder ────────────────────────────

    public function listMembers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $members = $this->planningService->listMembers($args['id'], $user);
            return ResponseFactory::json(['data' => $members], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function inviteMember(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);

        $targetUserId = trim((string) ($body['userId'] ?? ''));
        if ($targetUserId === '') {
            return ResponseFactory::json(['error' => 'user_id_required'], 400, $response);
        }

        try {
            $id = $this->planningService->inviteMember($user, $args['id'], $targetUserId);
            return ResponseFactory::json(['data' => ['id' => $id]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setMemberStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $status = trim((string) ($body['status'] ?? ''));

        try {
            $this->planningService->setMemberStatus($user, $args['id'], $args['userId'], $status);
            return ResponseFactory::json(['message' => 'member_updated'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function respondToInvitation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $status = trim((string) ($body['response'] ?? ''));

        try {
            $this->planningService->respondToInvitation($user, $args['id'], $status);
            return ResponseFactory::json(['message' => 'response_recorded'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeMember(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->planningService->removeMember($user, $args['id'], $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Themen ────────────────────────────

    public function setTopicStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $status = trim((string) ($body['status'] ?? ''));

        try {
            $topics = $this->planningService->setTopicStatus($user, $args['id'], $args['topic'], $status);
            return ResponseFactory::json(['data' => $topics], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Terminoptionen ────────────────────────────

    public function listDateOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $options = $this->planningService->listDateOptions($args['id'], $user);
            return ResponseFactory::json(['data' => $options], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function createDateOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->createDateOption($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $option], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateDateOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->updateDateOption($user, $args['id'], $args['dateOptionId'], $this->body($request));
            return ResponseFactory::json(['data' => $option], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteDateOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->planningService->deleteDateOption($user, $args['id'], $args['dateOptionId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setDateResponse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $availability = trim((string) ($body['availability'] ?? ''));

        try {
            $option = $this->planningService->setDateResponse($user, $args['id'], $args['dateOptionId'], $availability);
            return ResponseFactory::json(['data' => $option], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function finalizeDateOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->finalizeDateOption($user, $args['id'], $args['dateOptionId']);
            return ResponseFactory::json(['data' => $option], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Transport ────────────────────────────

    public function listTransport(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $transport = $this->planningService->listTransport($args['id'], $user);
            return ResponseFactory::json(['data' => $transport], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setTransport(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $transport = $this->planningService->setTransport($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $transport], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Unterkunftsoptionen ────────────────────────────

    public function listAccommodationOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $options = $this->planningService->listAccommodationOptions($args['id'], $user);
            return ResponseFactory::json(['data' => $options], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function createAccommodationOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->createAccommodationOption($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $option], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateAccommodationOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->updateAccommodationOption($user, $args['id'], $args['optionId'], $this->body($request));
            return ResponseFactory::json(['data' => $option], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteAccommodationOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->planningService->deleteAccommodationOption($user, $args['id'], $args['optionId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function selectAccommodationOption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->planningService->selectAccommodationOption($user, $args['id'], $args['optionId']);
            return ResponseFactory::json(['data' => $option], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    // ──────────────────────────── Eventvorschläge ────────────────────────────

    public function listEventSuggestions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $suggestions = $this->planningService->listEventSuggestions($args['id'], $user);
            return ResponseFactory::json(['data' => $suggestions], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function createEventSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $suggestion = $this->planningService->createEventSuggestion($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $suggestion], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function updateEventSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $suggestion = $this->planningService->updateEventSuggestion($user, $args['id'], $args['suggestionId'], $this->body($request));
            return ResponseFactory::json(['data' => $suggestion], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function deleteEventSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->planningService->deleteEventSuggestion($user, $args['id'], $args['suggestionId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function setEventInterest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $interest = trim((string) ($body['interest'] ?? ''));

        try {
            $suggestion = $this->planningService->setEventInterest($user, $args['id'], $args['suggestionId'], $interest);
            return ResponseFactory::json(['data' => $suggestion], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function confirmEventSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $confirmed = array_key_exists('confirmed', $body) ? (bool) $body['confirmed'] : true;

        try {
            $suggestion = $this->planningService->confirmEventSuggestion($user, $args['id'], $args['suggestionId'], $confirmed);
            return ResponseFactory::json(['data' => $suggestion], 200, $response);
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
        [$code, $status] = TravelPlanningError::resolve($e->getMessage());

        if ($status >= 500) {
            $this->logger->error('Travel planning request failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return ResponseFactory::json(['error' => $code], $status, $response);
    }
}
