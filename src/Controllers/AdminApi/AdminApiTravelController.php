<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTicketRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\TravelTripSubscriptionRepository;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\ImageService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\TravelChatService;
use Sinclear\Api\Services\TravelService;

final readonly class AdminApiTravelController
{
    use AdminApiConcern;

    public function __construct(
        private TravelService $travelService,
        private TravelChatService $travelChatService,
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelRelationRepository $travelRelationRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private EventRelationRepository $eventRelationRepo,
        private ForumRepository $forumRepo,
        private ForumMemberRepository $forumMemberRepo,
        private SubscriptionRepository $subscriptionRepo,
        private TravelTripSubscriptionRepository $tripSubscriptionRepo,
        private TravelTicketRepository $ticketRepo,
        private TravelChatRepository $travelChatRepo,
        private ChatConversationRepository $conversationRepo,
        private ImageService $imageService,
        private NotificationService $notificationService,
        private AdminAuditService $auditService,
        private LoggerInterface $logger,
    ) {}

    public function travel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $trips = array_map(fn(array $t) => $this->tripDto($t), $this->tripRepo->findAll());
        $events = array_map(fn(array $e) => $this->eventDto($e), $this->eventRepo->findAll());

        return ResponseFactory::json([
            'trips' => $trips,
            'events' => $events,
        ], 200, $response);
    }

    public function tripDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }

        $participants = array_map(static fn(array $p) => [
            'userId' => $p['userid'],
            'displayName' => $p['displayName'],
            'email' => $p['email'],
            'role' => ($p['role'] ?? 'participant') === 'leader' ? 'leader' : 'participant',
            'accommodation' => $p['accommodation'] ?? null,
        ], $this->travelRelationRepo->findParticipantRelationsByTrip($id));

        $accommodations = $this->accommodationRepo->findByTrip($id);

        $events = array_map(fn(array $e) => $this->eventDto($e), $this->eventRepo->findByTrip($id));

        $forumId = $trip['forumId'] ?? null;
        $forum = null;
        if ($forumId !== null) {
            $f = $this->forumRepo->findById($forumId);
            if ($f !== null) {
                $forum = ['id' => $f['id'], 'name' => $f['name']];
            }
        }

        $linkedSubscriptionIds = $this->tripSubscriptionRepo->findByTrip($id);
        $allSubscriptions = $this->subscriptionRepo->findAll();
        $subscriptions = array_values(array_filter(
            array_map(static fn(array $s) => in_array($s['id'], $linkedSubscriptionIds, true)
                ? ['id' => $s['id'], 'name' => $s['name']]
                : null,
                $allSubscriptions,
            ),
        ));

        return ResponseFactory::json([
            'data' => [
                ...$this->tripDto($trip),
                'participants' => $participants,
                'accommodations' => $accommodations,
                'events' => $events,
                'forum' => $forum,
                'subscriptions' => $subscriptions,
                'chat' => $this->tripChatInfo($id),
            ],
        ], 200, $response);
    }

    public function eventDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $participants = array_map(static fn(array $p) => [
            'userId' => $p['userId'],
            'displayName' => $p['displayName'],
            'email' => $p['email'],
            'role' => ($p['role'] ?? 'participant') === 'leader' ? 'leader' : 'participant',
            'createdAt' => $p['createdAt'],
        ], $this->eventRelationRepo->findByEvent($id));

        $dto = $this->eventDto($event);
        $dto['isStandalone'] = ($event['trip'] ?? null) === null;
        $dto['participants'] = $participants;
        $dto['chat'] = $this->eventChatInfo($id);

        return ResponseFactory::json(['data' => $dto], 200, $response);
    }

    // ---- Reisen ----

    public function createTrip(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->createTrip($user, $this->parsedBody($request));
            $this->auditService->record($user, 'create', 'trip', $trip['id'], $request);
            return ResponseFactory::json(['data' => $trip], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function updateTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $trip = $this->travelService->updateTrip($user, $args['id'], $this->parsedBody($request));
            $this->auditService->record($user, 'update', 'trip', $args['id'], $request);
            return ResponseFactory::json(['data' => $trip], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function deleteTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteTrip($user, $args['id']);
            $this->auditService->record($user, 'delete', 'trip', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function createTripChat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        $existing = $this->travelChatRepo->findByTripId($tripId);
        if ($existing !== null) {
            return ResponseFactory::json(['data' => $existing], 200, $response);
        }

        $chat = $this->travelChatService->createForTrip($tripId);
        $this->auditService->record($user, 'create_chat', 'trip', $tripId, $request);

        return ResponseFactory::json(['data' => $chat], 201, $response);
    }

    public function deleteTripChat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        $existing = $this->travelChatRepo->findByTripId($tripId);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'chat_not_found'], 404, $response);
        }

        $this->travelChatService->deleteForTrip($tripId);
        $this->auditService->record($user, 'delete_chat', 'trip', $tripId, $request);

        return ResponseFactory::noContent($response);
    }

    public function updateTripChatImage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];
        $body = $request->getParsedBody();

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        try {
            $travelChat = $this->travelChatRepo->findByTripId($tripId);
        } catch (\Throwable) {
            return ResponseFactory::json(['error' => 'chat_table_missing'], 500, $response);
        }
        if ($travelChat === null) {
            return ResponseFactory::json(['error' => 'chat_not_found'], 404, $response);
        }

        $image = null;
        if (isset($body['image']) && is_string($body['image']) && $body['image'] !== '') {
            try {
                $this->imageService->validate($body['image']);
                $image = $body['image'];
            } catch (\InvalidArgumentException $e) {
                return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
            }
        }

        try {
            $this->conversationRepo->updateImage($travelChat['conversationId'], $image);
        } catch (\Throwable $e) {
            $this->logger->error('updateTripChatImage: updateImage failed', ['exception' => $e]);
            return ResponseFactory::json(['error' => 'chat_image_update_failed', 'detail' => $e->getMessage()], 500, $response);
        }

        $this->auditService->record($user, 'update_chat_image', 'trip', $tripId, $request);

        return ResponseFactory::json(['data' => ['conversationId' => $travelChat['conversationId'], 'image' => $image]], 200, $response);
    }

    public function addTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $userId = trim((string) ($body['userId'] ?? ''));
        if ($userId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        $accommodation = isset($body['accommodation']) && is_string($body['accommodation']) && $body['accommodation'] !== ''
            ? trim($body['accommodation']) : null;

        try {
            $relationId = $this->travelService->addParticipant($user, $args['id'], $userId, $accommodation);
            $this->auditService->record($user, 'add_participant', 'trip', $args['id'], $request);
            return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function removeTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->removeParticipant($user, $args['id'], $args['userId']);
            $this->auditService->record($user, 'remove_participant', 'trip', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function setTripParticipantRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);
        $role = trim((string) ($body['role'] ?? ''));

        try {
            $this->travelService->setParticipantRole($user, $args['id'], $args['userId'], $role);
            $this->auditService->record($user, 'set_role', 'trip', $args['id'], $request);
            return ResponseFactory::json(['message' => 'role_updated'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function updateParticipantAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];
        $userId = $args['userId'];
        $body = $request->getParsedBody();

        if (!$this->travelRelationRepo->isParticipant($userId, $tripId)) {
            return ResponseFactory::json(['error' => 'not_a_participant'], 404, $response);
        }

        $accommodation = isset($body['accommodation']) && is_string($body['accommodation']) && $body['accommodation'] !== ''
            ? trim($body['accommodation']) : null;

        $this->travelRelationRepo->updateAccommodation($userId, $tripId, $accommodation);

        if ($accommodation !== null) {
            $this->notifyTripAccommodationAdded($tripId, $userId, $accommodation);
        }

        $this->auditService->record($user, 'set_accommodation', 'trip', $tripId, $request);

        return ResponseFactory::json(['message' => 'accommodation_updated'], 200, $response);
    }

    public function linkTripForum(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];
        $body = $request->getParsedBody();

        $forumId = isset($body['forumId']) && is_string($body['forumId']) && $body['forumId'] !== ''
            ? trim($body['forumId']) : null;

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        if ($forumId !== null) {
            $forum = $this->forumRepo->findById($forumId);
            if ($forum === null) {
                return ResponseFactory::json(['error' => 'forum_not_found'], 404, $response);
            }

            $existingTrip = $this->tripRepo->findByForumId($forumId);
            if ($existingTrip !== null && $existingTrip['id'] !== $tripId) {
                return ResponseFactory::json(['error' => 'forum_already_linked'], 409, $response);
            }
        }

        $this->tripRepo->update($tripId, ['forumId' => $forumId]);

        if ($forumId !== null) {
            $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
            foreach ($participants as $p) {
                $existingMember = $this->forumMemberRepo->findByForumAndUser($forumId, $p['id']);
                if ($existingMember === null) {
                    $this->forumMemberRepo->join($forumId, $p['id']);
                }
            }
        }

        $this->auditService->record($user, 'link_forum', 'trip', $tripId, $request);

        return ResponseFactory::json(['data' => ['forumId' => $forumId]], 200, $response);
    }

    public function linkTripSubscription(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];
        $body = $request->getParsedBody();

        $subscriptionId = isset($body['subscriptionId']) && is_string($body['subscriptionId']) && $body['subscriptionId'] !== ''
            ? trim($body['subscriptionId']) : null;

        if ($subscriptionId === null) {
            return ResponseFactory::json(['error' => 'subscriptionId_required'], 400, $response);
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        $subscription = $this->subscriptionRepo->findById($subscriptionId);
        if ($subscription === null) {
            return ResponseFactory::json(['error' => 'subscription_not_found'], 404, $response);
        }

        $this->tripSubscriptionRepo->add($tripId, $subscriptionId);
        $this->notifyTripSubscriptionAdded($tripId, $subscriptionId);
        $this->auditService->record($user, 'link_subscription', 'trip', $tripId, $request);

        return ResponseFactory::json(['message' => 'subscription_linked'], 200, $response);
    }

    public function unlinkTripSubscription(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $tripId = $args['id'];
        $subscriptionId = $args['subscriptionId'];

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }
        if (!$this->tripIsOperational($trip)) {
            return $this->tripNotActiveResponse($response);
        }

        $this->tripSubscriptionRepo->remove($tripId, $subscriptionId);
        $this->auditService->record($user, 'unlink_subscription', 'trip', $tripId, $request);

        return ResponseFactory::noContent($response);
    }

    public function createTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->createAccommodation($user, $args['id'], $this->parsedBody($request));
            $this->auditService->record($user, 'create_accommodation', 'trip', $args['id'], $request);
            return ResponseFactory::json(['data' => $accommodation], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function updateTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $accommodation = $this->travelService->updateAccommodation($user, $args['id'], $args['accId'], $this->parsedBody($request));
            $this->auditService->record($user, 'update_accommodation', 'trip', $args['id'], $request);
            return ResponseFactory::json(['data' => $accommodation], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function deleteTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteAccommodation($user, $args['id'], $args['accId']);
            $this->auditService->record($user, 'delete_accommodation', 'trip', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function deleteAccommodationGlobally(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->deleteAccommodationGlobally($user, $args['id']);
            $this->auditService->record($user, 'delete_accommodation', 'accommodation', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    // ---- Events ----

    public function createEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);
        $tripId = isset($body['trip']) && is_string($body['trip']) && trim($body['trip']) !== ''
            ? trim($body['trip']) : null;

        try {
            $event = $tripId !== null
                ? $this->travelService->createTripEvent($user, $tripId, $body)
                : $this->travelService->createStandaloneEvent($user, $body);
            $this->auditService->record($user, 'create', 'event', $event['id'], $request);
            return ResponseFactory::json(['data' => $event], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function updateEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $event = $this->eventRepo->findById($args['id']);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $body = $this->parsedBody($request);
        $tripId = $event['trip'] ?? null;

        try {
            $updated = $tripId !== null
                ? $this->travelService->updateTripEvent($user, $tripId, $args['id'], $body)
                : $this->travelService->updateStandaloneEvent($user, $args['id'], $body);
            $this->auditService->record($user, 'update', 'event', $args['id'], $request);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function deleteEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $event = $this->eventRepo->findById($args['id']);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        try {
            $tripId = $event['trip'] ?? null;
            if ($tripId !== null) {
                $this->travelService->deleteTripEvent($user, $tripId, $args['id']);
            } else {
                $this->travelService->deleteStandaloneEvent($user, $args['id']);
            }
            $this->auditService->record($user, 'delete', 'event', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function createEventChat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $eventId = $args['id'];

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $existing = $this->travelChatRepo->findByEventId($eventId);
        if ($existing !== null) {
            return ResponseFactory::json(['data' => $existing], 200, $response);
        }

        $chat = $this->travelChatService->createForEvent($eventId);
        $this->auditService->record($user, 'create_chat', 'event', $eventId, $request);

        return ResponseFactory::json(['data' => $chat], 201, $response);
    }

    public function deleteEventChat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $eventId = $args['id'];

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $existing = $this->travelChatRepo->findByEventId($eventId);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'chat_not_found'], 404, $response);
        }

        $this->travelChatService->deleteForEvent($eventId);
        $this->auditService->record($user, 'delete_chat', 'event', $eventId, $request);

        return ResponseFactory::noContent($response);
    }

    public function updateEventChatImage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $eventId = $args['id'];
        $body = $request->getParsedBody();

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        try {
            $travelChat = $this->travelChatRepo->findByEventId($eventId);
        } catch (\Throwable) {
            return ResponseFactory::json(['error' => 'chat_table_missing'], 500, $response);
        }
        if ($travelChat === null) {
            return ResponseFactory::json(['error' => 'chat_not_found'], 404, $response);
        }

        $image = null;
        if (isset($body['image']) && is_string($body['image']) && $body['image'] !== '') {
            try {
                $this->imageService->validate($body['image']);
                $image = $body['image'];
            } catch (\InvalidArgumentException $e) {
                return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
            }
        }

        try {
            $this->conversationRepo->updateImage($travelChat['conversationId'], $image);
        } catch (\Throwable $e) {
            $this->logger->error('updateEventChatImage: updateImage failed', ['exception' => $e]);
            return ResponseFactory::json(['error' => 'chat_image_update_failed', 'detail' => $e->getMessage()], 500, $response);
        }

        $this->auditService->record($user, 'update_chat_image', 'event', $eventId, $request);

        return ResponseFactory::json(['data' => ['conversationId' => $travelChat['conversationId'], 'image' => $image]], 200, $response);
    }

    public function addEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $userId = trim((string) ($body['userId'] ?? ''));
        if ($userId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        try {
            $relationId = $this->travelService->addEventParticipant($user, $args['id'], $userId);
            $this->auditService->record($user, 'add_participant', 'event', $args['id'], $request);
            return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function removeEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->travelService->removeEventParticipant($user, $args['id'], $args['userId']);
            $this->auditService->record($user, 'remove_participant', 'event', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function setEventParticipantRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);
        $role = trim((string) ($body['role'] ?? ''));

        try {
            $this->travelService->setStandaloneEventParticipantRole($user, $args['id'], $args['userId'], $role);
            $this->auditService->record($user, 'set_role', 'event', $args['id'], $request);
            return ResponseFactory::json(['message' => 'role_updated'], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    public function convertEventTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $targetTripId = isset($body['trip']) && is_string($body['trip']) && trim($body['trip']) !== ''
            ? trim($body['trip']) : null;

        try {
            $event = $this->travelService->convertEvent($user, $args['id'], $targetTripId);
            $this->auditService->record($user, 'convert', 'event', $args['id'], $request);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->travelErrorResponse($e, $response);
        }
    }

    // ---- Tickets ----

    public function createTicket(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $type = isset($body['type']) && is_string($body['type']) ? trim($body['type']) : null;
        if (!in_array($type, ['event', 'trip', 'user'], true)) {
            return ResponseFactory::json(['error' => 'invalid_type'], 400, $response);
        }

        $data = [
            'type' => $type,
            'event' => isset($body['event']) && is_string($body['event']) && $body['event'] !== '' ? trim($body['event']) : null,
            'trip' => isset($body['trip']) && is_string($body['trip']) && $body['trip'] !== '' ? trim($body['trip']) : null,
            'user' => isset($body['user']) && is_string($body['user']) && $body['user'] !== '' ? trim($body['user']) : null,
            'qrcode' => isset($body['qrcode']) && is_string($body['qrcode']) ? trim($body['qrcode']) : null,
            'image' => isset($body['image']) && is_string($body['image']) ? trim($body['image']) : null,
        ];

        $id = $this->ticketRepo->create($data);
        $ticket = $this->ticketRepo->findById($id);

        $this->notifyTicketAdded($ticket, $request);
        $this->auditService->record($user, 'create', 'ticket', $id, $request);

        return ResponseFactory::json(['data' => $ticket], 201, $response);
    }

    public function updateTicket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $ticket = $this->ticketRepo->findById($id);
        if ($ticket === null) {
            return ResponseFactory::json(['error' => 'ticket_not_found'], 404, $response);
        }

        $data = [];
        foreach (['type', 'event', 'trip', 'user', 'qrcode', 'image'] as $field) {
            if (isset($body[$field])) {
                $data[$field] = is_string($body[$field]) ? trim($body[$field]) : null;
            }
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->ticketRepo->update($id, $data);
        $updated = $this->ticketRepo->findById($id);
        $this->auditService->record($user, 'update', 'ticket', $id, $request);

        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function deleteTicket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $ticket = $this->ticketRepo->findById($id);
        if ($ticket === null) {
            return ResponseFactory::json(['error' => 'ticket_not_found'], 404, $response);
        }

        $this->ticketRepo->delete($id);
        $this->auditService->record($user, 'delete', 'ticket', $id, $request);

        return ResponseFactory::noContent($response);
    }

    // ---- DTO/Helper ----

    /** @param array<string, mixed> $t */
    private function tripDto(array $t): array
    {
        return [
            'id' => $t['id'],
            'name' => $t['name'] ?? '',
            'description' => $t['description'] ?? '',
            ...self::timingFields($t),
            'hastickets' => $t['hastickets'] ?? '0',
            'ticket' => $t['ticket'] ?? '',
            'ticketUrl' => $t['ticketUrl'] ?? '',
        ];
    }

    /** @param array<string, mixed> $e */
    private function eventDto(array $e): array
    {
        return [
            'id' => $e['ID'],
            'name' => $e['name'] ?? '',
            'description' => $e['description'] ?? '',
            'trip' => $e['trip'] ?? null,
            ...self::timingFields($e),
            'hastickets' => $e['hastickets'] ?? '0',
            'ticket' => $e['ticket'] ?? '',
            'ticketUrl' => $e['ticketUrl'] ?? '',
            'url' => $e['url'] ?? '',
            'image' => self::isValidImageData($e['image'] ?? null) ? $e['image'] : '',
            'organizer' => $e['organizer'] ?? '',
            'address' => $e['address'] ?? '',
            'latitude' => $e['latitude'] ?? '',
            'longitude' => $e['longitude'] ?? '',
            'OSMID' => $e['OSMID'] ?? '',
            'citySlug' => $e['citySlug'] ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function tripChatInfo(string $tripId): array
    {
        try {
            $travelChat = $this->travelChatRepo->findByTripId($tripId);
        } catch (\Throwable) {
            $travelChat = null;
        }

        if ($travelChat === null) {
            return ['active' => false, 'conversationId' => null, 'memberCount' => null, 'image' => null];
        }

        return $this->chatInfo($travelChat['conversationId']);
    }

    /** @return array<string, mixed> */
    private function eventChatInfo(string $eventId): array
    {
        try {
            $travelChat = $this->travelChatRepo->findByEventId($eventId);
        } catch (\Throwable) {
            $travelChat = null;
        }

        if ($travelChat === null) {
            return ['active' => false, 'conversationId' => null, 'memberCount' => null, 'image' => null];
        }

        return $this->chatInfo($travelChat['conversationId']);
    }

    /** @return array<string, mixed> */
    private function chatInfo(string $conversationId): array
    {
        $memberCount = null;
        $image = null;

        try {
            $memberCount = $this->conversationRepo->countParticipants($conversationId);
        } catch (\Throwable) {
        }

        try {
            $conversation = $this->conversationRepo->findById($conversationId);
            if ($conversation !== null) {
                $image = $conversation['image'] ?? null;
            }
        } catch (\Throwable) {
        }

        return [
            'active' => true,
            'conversationId' => $conversationId,
            'memberCount' => $memberCount,
            'image' => $image,
        ];
    }

    private static function isValidImageData(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        $header = substr($decoded, 0, 4);
        return str_starts_with($header, "\xFF\xD8\xFF")
            || str_starts_with($header, "\x89PNG")
            || ($header === 'RIFF' && substr($decoded, 8, 4) === 'WEBP');
    }

    /** @param array<string, mixed> $ticket */
    private function notifyTicketAdded(array $ticket, ServerRequestInterface $request): void
    {
        if ($ticket['type'] === 'user') {
            return;
        }

        $uploadedBy = $this->requireUser($request);

        if ($ticket['type'] === 'trip' && $ticket['trip'] !== null) {
            $participants = $this->travelRelationRepo->findParticipantsByTrip($ticket['trip']);
            foreach ($participants as $participant) {
                if ($participant['id'] === $uploadedBy->id) {
                    continue;
                }
                $this->notificationService->create(
                    userId: $participant['id'],
                    type: 'trip_ticket_added',
                    title: '',
                    body: '',
                    data: [
                        ['relation' => 'ticket', 'object' => 'Ticket', 'identifier' => $ticket['ID']],
                        ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $ticket['trip']],
                        ['relation' => 'uploaded_by', 'object' => 'User', 'identifier' => $uploadedBy->id],
                    ],
                );
            }
        } elseif ($ticket['type'] === 'event' && $ticket['event'] !== null) {
            $event = $this->eventRepo->findById($ticket['event']);
            if ($event === null) {
                return;
            }

            $isTripEvent = $event['trip'] !== null;
            $type = $isTripEvent ? 'trip_event_ticket_added' : 'standalone_event_ticket_added';

            $participants = $this->eventRelationRepo->findByEvent($ticket['event']);
            foreach ($participants as $participant) {
                if ($participant['userId'] === $uploadedBy->id) {
                    continue;
                }
                $data = [
                    ['relation' => 'ticket', 'object' => 'Ticket', 'identifier' => $ticket['ID']],
                    ['relation' => 'event', 'object' => 'Event', 'identifier' => $ticket['event']],
                    ['relation' => 'uploaded_by', 'object' => 'User', 'identifier' => $uploadedBy->id],
                ];
                $this->notificationService->create(
                    userId: $participant['userId'],
                    type: $type,
                    title: '',
                    body: '',
                    data: $data,
                );
            }
        }
    }

    private function notifyTripAccommodationAdded(string $tripId, string $userId, string $accommodationId): void
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        $accommodation = $this->accommodationRepo->findById($accommodationId);
        if ($accommodation === null) {
            return;
        }

        $this->notificationService->create(
            userId: $userId,
            type: 'trip_accommodation_added',
            title: '',
            body: '',
            data: [
                ['relation' => 'accommodation', 'object' => 'Accommodation', 'identifier' => $accommodationId],
                ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ['relation' => 'user', 'object' => 'User', 'identifier' => $userId],
            ],
        );
    }

    private function notifyTripSubscriptionAdded(string $tripId, string $subscriptionId): void
    {
        $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        foreach ($participants as $participant) {
            $this->notificationService->create(
                userId: $participant['id'],
                type: 'trip_subscription_added',
                title: '',
                body: '',
                data: [
                    ['relation' => 'subscription', 'object' => 'Subscription', 'identifier' => $subscriptionId],
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ],
            );
        }
    }
}
