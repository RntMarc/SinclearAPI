<?php

namespace Sinclear\Api\Services;

use PDO;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTicketRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\TravelTripSubscriptionRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\TravelPolicy;
use Sinclear\Api\Support\DateTimeValue;
use Sinclear\Api\Support\TravelInput;

final readonly class TravelService
{
    public function __construct(
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private TravelRelationRepository $relationRepo,
        private EventRelationRepository $eventRelationRepo,
        private TravelTripSubscriptionRepository $tripSubscriptionRepo,
        private ForumRepository $forumRepo,
        private TravelTicketRepository $ticketRepo,
        private TravelChatRepository $travelChatRepo,
        private UserRepository $userRepo,
        private TravelPolicy $policy,
        private TravelChatService $travelChatService,
        private TravelNotificationService $notificationService,
        private ImageService $imageService,
        private ForumService $forumService,
        private TravelPlanningService $travelPlanningService,
        private PDO $pdo,
    ) {}

    // ──────────────────────────── Lesen ────────────────────────────

    public function listTrips(AuthenticatedUser $user, int $page, int $limit): array
    {
        $result = $this->tripRepo->findByParticipant($user->id, $page, $limit);
        $result['data'] = array_map(
            fn(array $t) => $this->enrichTrip($t, $user),
            $result['data'],
        );
        return $result;
    }

    public function getTrip(string $id, AuthenticatedUser $user): array
    {
        if (!$user->isAdmin && !$this->relationRepo->isParticipant($user->id, $id)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->enrichTrip($trip, $user);
    }

    public function listEvents(string $tripId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $events = $this->eventRepo->findByTrip($tripId);
        return array_map(fn(array $e) => $this->enrichEvent($e, $user), $events);
    }

    public function getEvent(string $tripId, string $eventId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $event = $this->eventRepo->findByIdAndTrip($eventId, $tripId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event, $user);
    }

    public function listStandaloneEvents(AuthenticatedUser $user, int $page, int $limit): array
    {
        $result = $this->eventRepo->findStandaloneByParticipant($user->id, $page, $limit);
        $result['data'] = array_map(fn(array $e) => $this->enrichEvent($e, $user), $result['data']);
        return $result;
    }

    public function getStandaloneEvent(string $eventId, AuthenticatedUser $user): array
    {
        $event = $this->eventRepo->findStandaloneByIdAndParticipant($eventId, $user->id);
        if ($event === null && $user->isAdmin) {
            $candidate = $this->eventRepo->findById($eventId);
            $event = ($candidate !== null && ($candidate['trip'] ?? null) === null) ? $candidate : null;
        }
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event, $user);
    }

    public function listAccommodations(string $tripId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $accommodations = $this->accommodationRepo->findByTrip($tripId);
        return array_map(
            fn(array $a) => $this->enrichAccommodation($a, $tripId),
            $accommodations,
        );
    }

    public function getAccommodation(string $tripId, string $accommodationId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $accommodation = $this->accommodationRepo->findByIdAndTrip($accommodationId, $tripId);
        if ($accommodation === null) {
            throw new \RuntimeException('Accommodation not found');
        }

        return $this->enrichAccommodation($accommodation, $tripId);
    }

    public function listParticipants(string $tripId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->relationRepo->findParticipantsByTrip($tripId);
    }

    public function getEventById(string $eventId, AuthenticatedUser $user): array
    {
        $event = $this->eventRepo->findByIdWithAccess($eventId, $user->id);
        if ($event === null && $user->isAdmin) {
            $event = $this->eventRepo->findById($eventId);
        }
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event, $user);
    }

    public function getTripSubscriptions(string $tripId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->tripSubscriptionRepo->findByTripWithUserAccess($tripId, $user->id);
    }

    public function listTripTickets(string $tripId, AuthenticatedUser $user): array
    {
        if (!$this->relationRepo->isParticipant($user->id, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return array_merge(
            $this->ticketRepo->findByTrip($tripId),
            $this->ticketRepo->findByUserAndTrip($user->id, $tripId),
        );
    }

    public function listEventTickets(string $eventId, AuthenticatedUser $user): array
    {
        $event = $this->eventRepo->findByIdWithAccess($eventId, $user->id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return array_merge(
            $this->ticketRepo->findByEvent($eventId),
            $this->ticketRepo->findByUserAndEvent($user->id, $eventId),
        );
    }

    public function listUserTickets(string $userId): array
    {
        return $this->ticketRepo->findByUser($userId);
    }

    public function createUserTicket(string $userId, array $data): array
    {
        $event = $data['event'] ?? null;
        $trip = $data['trip'] ?? null;

        if ($event !== null && $trip !== null) {
            throw new \RuntimeException('Not a participant');
        }

        $id = $this->ticketRepo->create([
            'type' => 'user',
            'title' => $data['title'] ?? null,
            'user' => $userId,
            'event' => $event,
            'trip' => $trip,
            'qrcode' => $data['qrcode'] ?? null,
            'image' => $data['image'] ?? null,
        ]);

        $ticket = $this->ticketRepo->findById($id);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket creation failed');
        }

        return $ticket;
    }

    public function updateUserTicket(string $ticketId, string $userId, array $data): array
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        if ($ticket['type'] !== 'user' || ($ticket['user'] ?? null) !== $userId) {
            throw new \RuntimeException('Not a participant');
        }

        $event = $data['event'] ?? null;
        $trip = $data['trip'] ?? null;

        if ($event !== null && $trip !== null) {
            throw new \RuntimeException('Not a participant');
        }

        $update = [];
        if (array_key_exists('title', $data)) {
            $update['title'] = $data['title'];
        }
        if (array_key_exists('qrcode', $data)) {
            $update['qrcode'] = $data['qrcode'];
        }
        if (array_key_exists('image', $data)) {
            $update['image'] = $data['image'];
        }
        if (array_key_exists('event', $data)) {
            $update['event'] = $data['event'];
        }
        if (array_key_exists('trip', $data)) {
            $update['trip'] = $data['trip'];
        }

        if ($update !== []) {
            $this->ticketRepo->update($ticketId, $update);
        }

        $updated = $this->ticketRepo->findById($ticketId);
        if ($updated === null) {
            throw new \RuntimeException('Ticket not found');
        }

        return $updated;
    }

    public function deleteUserTicket(string $ticketId, string $userId): void
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        if ($ticket['type'] !== 'user' || ($ticket['user'] ?? null) !== $userId) {
            throw new \RuntimeException('Not a participant');
        }

        $this->ticketRepo->delete($ticketId);
    }

    // ──────────────────────────── Reisen schreiben ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createTrip(AuthenticatedUser $user, array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name required');
        }

        $timing = $this->timingOrFail($body, true);

        $id = $this->tripRepo->create(array_merge($timing, [
            'name' => $name,
            'description' => TravelInput::nullableString($body, 'description'),
            'hastickets' => TravelInput::boolFlag($body, 'hastickets'),
            'ticket' => TravelInput::nullableString($body, 'ticket'),
            'ticketUrl' => TravelInput::nullableString($body, 'ticketUrl'),
        ]));

        $this->relationRepo->addParticipant($user->id, $id, null, TravelPolicy::ROLE_LEADER);

        return $this->getTrip($id, $user);
    }

    /** @param array<string, mixed> $body */
    public function updateTrip(AuthenticatedUser $user, string $id, array $body): array
    {
        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $id);

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = TravelInput::nullableString($body, 'description');
        }
        if (TravelInput::hasTimingFields($body)) {
            $data = array_merge($data, $this->timingOrFail($body, (int) ($trip['allDay'] ?? 0) === 1));
        }
        if (isset($body['hastickets'])) {
            $data['hastickets'] = TravelInput::boolFlag($body, 'hastickets');
        }
        if (isset($body['ticket'])) {
            $data['ticket'] = TravelInput::nullableString($body, 'ticket');
        }
        if (isset($body['ticketUrl'])) {
            $data['ticketUrl'] = TravelInput::nullableString($body, 'ticketUrl');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $changedFields = TravelInput::detectTripChanges($trip, $data);
        $this->tripRepo->update($id, $data);

        if ($changedFields !== []) {
            $this->notificationService->notifyTripInfoChanged($id, $changedFields, $user->id);
        }

        return $this->getTrip($id, $user);
    }

    public function deleteTrip(AuthenticatedUser $user, string $id): void
    {
        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $id);

        $forumId = $trip['forumId'] ?? null;

        $this->pdo->beginTransaction();

        try {
            if ($forumId !== null && $this->forumRepo->findById($forumId) !== null) {
                $this->forumService->deleteForum($forumId);
            }

            $this->travelChatService->deleteForTrip($id);
            $this->travelPlanningService->deletePlanningData($id);
            $this->tripRepo->delete($id);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ──────────────────────────── Events schreiben ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createTripEvent(AuthenticatedUser $user, string $tripId, array $body): array
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        return $this->createEventInternal($user, $body, $tripId);
    }

    /** @param array<string, mixed> $body */
    public function updateTripEvent(AuthenticatedUser $user, string $tripId, string $eventId, array $body): array
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $event = $this->eventRepo->findByIdAndTrip($eventId, $tripId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        if (array_key_exists('trip', $body)) {
            $target = $this->normalizeTripTarget($body['trip']);
            if ($target !== $tripId) {
                $event = $this->convertEvent($user, $eventId, $target);
                return $this->updateEventInternal($user, $event, $body);
            }
        }

        return $this->updateEventInternal($user, $event, $body);
    }

    public function deleteTripEvent(AuthenticatedUser $user, string $tripId, string $eventId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $event = $this->eventRepo->findByIdAndTrip($eventId, $tripId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        $this->pdo->beginTransaction();

        try {
            $this->travelChatService->deleteForEvent($eventId);
            $this->eventRepo->delete($eventId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $body */
    public function createStandaloneEvent(AuthenticatedUser $user, array $body): array
    {
        return $this->createEventInternal($user, $body, null);
    }

    /** @param array<string, mixed> $body */
    public function updateStandaloneEvent(AuthenticatedUser $user, string $eventId, array $body): array
    {
        $event = $this->findStandaloneEvent($eventId);
        $this->assertCanManageStandaloneEvent($user, $eventId);

        if (array_key_exists('trip', $body)) {
            $target = $this->normalizeTripTarget($body['trip']);
            if ($target !== null) {
                $event = $this->convertEvent($user, $eventId, $target);
            }
        }

        return $this->updateEventInternal($user, $event, $body);
    }

    public function deleteStandaloneEvent(AuthenticatedUser $user, string $eventId): void
    {
        $this->findStandaloneEvent($eventId);
        $this->assertCanManageStandaloneEvent($user, $eventId);

        $this->pdo->beginTransaction();

        try {
            $this->travelChatService->deleteForEvent($eventId);
            $this->eventRepo->delete($eventId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Haengt ein Event an eine Reise oder loest es zu einem Standalone-Event.
     * Erlaubt nur fuer Leader von Quelle UND Ziel (Admin immer).
     *
     * @return array<string, mixed> aktualisiertes Event
     */
    public function convertEvent(AuthenticatedUser $user, string $eventId, ?string $targetTripId): array
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $currentTripId = $event['trip'] ?? null;
        if ($currentTripId === $targetTripId) {
            throw new \RuntimeException('Invalid conversion');
        }

        $sourceRole = $currentTripId !== null
            ? $this->relationRepo->getRole($user->id, $currentTripId)
            : $this->eventRelationRepo->getRole($eventId, $user->id);

        if ($targetTripId !== null) {
            if ($this->tripRepo->findById($targetTripId) === null) {
                throw new \RuntimeException('Trip not found');
            }
            $this->assertTripIsActive($targetTripId);
            $destRole = $this->relationRepo->getRole($user->id, $targetTripId);
        } else {
            // Ziel ist ein Standalone-Event: der Handelnde wird dessen Leader.
            $destRole = TravelPolicy::ROLE_LEADER;
        }

        if (!$this->policy->canConvert($user, $sourceRole, $destRole)) {
            throw new \RuntimeException('Conversion not allowed');
        }

        $this->eventRepo->update($eventId, ['trip' => $targetTripId]);
        $this->eventRelationRepo->resetRoles($eventId);

        if ($targetTripId === null) {
            if ($this->eventRelationRepo->getRole($eventId, $user->id) === null) {
                $this->eventRelationRepo->addParticipant($eventId, $user->id, TravelPolicy::ROLE_LEADER);
            } else {
                $this->eventRelationRepo->updateRole($eventId, $user->id, TravelPolicy::ROLE_LEADER);
            }
        }

        $updated = $this->eventRepo->findById($eventId);
        if ($updated === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->notificationService->notifyEventConverted($updated, $currentTripId, $targetTripId, $user->id);

        return $updated;
    }

    // ──────────────────────────── Teilnehmer & Rollen ────────────────────────────

    public function addParticipant(AuthenticatedUser $user, string $tripId, string $targetUserId, ?string $accommodationId): string
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        if ($this->userRepo->findById($targetUserId) === null) {
            throw new \RuntimeException('User not found');
        }

        if ($this->relationRepo->isParticipant($targetUserId, $tripId)) {
            throw new \RuntimeException('Already a participant');
        }

        $id = $this->relationRepo->addParticipant($targetUserId, $tripId, $accommodationId);

        $this->travelChatService->syncTripMembers($tripId);
        $this->notificationService->notifyTripUserAdded($tripId, $targetUserId, $user->id);

        return $id;
    }

    public function removeParticipant(AuthenticatedUser $user, string $tripId, string $targetUserId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        $role = $this->relationRepo->getRole($targetUserId, $tripId);
        if ($role === null) {
            throw new \RuntimeException('Not a participant');
        }

        if ($role === TravelPolicy::ROLE_LEADER && $this->relationRepo->countLeaders($tripId) <= 1) {
            throw new \RuntimeException('Last leader remains');
        }

        $this->relationRepo->removeByUserAndTrip($targetUserId, $tripId);
        $this->travelChatService->syncTripMembers($tripId);
    }

    public function setParticipantRole(AuthenticatedUser $user, string $tripId, string $targetUserId, string $role): void
    {
        if (!in_array($role, [TravelPolicy::ROLE_LEADER, TravelPolicy::ROLE_PARTICIPANT], true)) {
            throw new \RuntimeException('Invalid role');
        }

        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        $current = $this->relationRepo->getRole($targetUserId, $tripId);
        if ($current === null) {
            throw new \RuntimeException('Not a participant');
        }

        if ($current === TravelPolicy::ROLE_LEADER
            && $role === TravelPolicy::ROLE_PARTICIPANT
            && $this->relationRepo->countLeaders($tripId) <= 1
        ) {
            throw new \RuntimeException('Last leader remains');
        }

        if ($current !== $role) {
            $this->relationRepo->updateRole($targetUserId, $tripId, $role);
            $this->notificationService->notifyTripRoleChanged(
                $tripId,
                $targetUserId,
                $role === TravelPolicy::ROLE_LEADER,
                $user->id,
            );
        }
    }

    public function addEventParticipant(AuthenticatedUser $user, string $eventId, string $targetUserId): string
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->assertCanManageEvent($user, $event);

        if ($this->userRepo->findById($targetUserId) === null) {
            throw new \RuntimeException('User not found');
        }

        if ($this->eventRelationRepo->getRole($eventId, $targetUserId) !== null) {
            throw new \RuntimeException('Already a participant');
        }

        $id = $this->eventRelationRepo->addParticipant($eventId, $targetUserId);

        $this->travelChatService->syncEventMembers($eventId);
        $this->notificationService->notifyEventUserAdded($event, $targetUserId, $user->id);

        return $id;
    }

    public function removeEventParticipant(AuthenticatedUser $user, string $eventId, string $targetUserId): void
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->assertCanManageEvent($user, $event);

        $role = $this->eventRelationRepo->getRole($eventId, $targetUserId);
        if ($role === null) {
            throw new \RuntimeException('Not a participant');
        }

        if ($role === TravelPolicy::ROLE_LEADER && $this->eventRelationRepo->countLeaders($eventId) <= 1) {
            throw new \RuntimeException('Last leader remains');
        }

        $this->eventRelationRepo->removeByEventAndUser($eventId, $targetUserId);
        $this->travelChatService->syncEventMembers($eventId);
    }

    public function setStandaloneEventParticipantRole(AuthenticatedUser $user, string $eventId, string $targetUserId, string $role): void
    {
        if (!in_array($role, [TravelPolicy::ROLE_LEADER, TravelPolicy::ROLE_PARTICIPANT], true)) {
            throw new \RuntimeException('Invalid role');
        }

        $this->findStandaloneEvent($eventId);
        $this->assertCanManageStandaloneEvent($user, $eventId);

        $current = $this->eventRelationRepo->getRole($eventId, $targetUserId);
        if ($current === null) {
            throw new \RuntimeException('Not a participant');
        }

        if ($current === TravelPolicy::ROLE_LEADER
            && $role === TravelPolicy::ROLE_PARTICIPANT
            && $this->eventRelationRepo->countLeaders($eventId) <= 1
        ) {
            throw new \RuntimeException('Last leader remains');
        }

        if ($current !== $role) {
            $this->eventRelationRepo->updateRole($eventId, $targetUserId, $role);
            $this->notificationService->notifyStandaloneEventRoleChanged(
                $eventId,
                $targetUserId,
                $role === TravelPolicy::ROLE_LEADER,
                $user->id,
            );
        }
    }

    /**
     * Fuegt einen Nutzer einem Reise-Event hinzu (nur Reiseleiter).
     */
    public function addTripEventParticipant(AuthenticatedUser $user, string $tripId, string $eventId, string $targetUserId): string
    {
        if ($this->eventRepo->findByIdAndTrip($eventId, $tripId) === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->addEventParticipant($user, $eventId, $targetUserId);
    }

    /**
     * Entfernt einen Nutzer aus einem Reise-Event (nur Reiseleiter).
     */
    public function removeTripEventParticipant(AuthenticatedUser $user, string $tripId, string $eventId, string $targetUserId): void
    {
        if ($this->eventRepo->findByIdAndTrip($eventId, $tripId) === null) {
            throw new \RuntimeException('Event not found');
        }

        $this->removeEventParticipant($user, $eventId, $targetUserId);
    }

    public function setParticipantAccommodation(AuthenticatedUser $user, string $tripId, string $targetUserId, ?string $accommodationId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertTripIsActive($tripId);

        if ($this->relationRepo->getRole($targetUserId, $tripId) === null) {
            throw new \RuntimeException('Not a participant');
        }

        // Reiseleiter duerfen jedem Teilnehmer eine Unterkunft zuweisen,
        // Mitreisende nur sich selbst.
        $isSelf = $targetUserId === $user->id;
        $allowed = $isSelf
            ? $this->policy->canAssignOwnAccommodation(
                $user,
                $this->relationRepo->isParticipant($user->id, $tripId),
            )
            : $this->policy->canAssignAccommodationToOther(
                $user,
                $this->relationRepo->getRole($user->id, $tripId),
            );

        if (!$allowed) {
            throw new \RuntimeException('Not a leader');
        }

        if ($accommodationId !== null) {
            if ($this->accommodationRepo->findById($accommodationId) === null) {
                throw new \RuntimeException('Accommodation not found');
            }
            // Wiederverwendbare Unterkunft bei Bedarf mit der Reise verknuepfen.
            $this->accommodationRepo->linkToTrip($tripId, $accommodationId);
        }

        $this->relationRepo->updateAccommodation($targetUserId, $tripId, $accommodationId);

        if ($accommodationId !== null) {
            $this->notificationService->notifyTripAccommodationAdded($tripId, $targetUserId, $accommodationId);
        }
    }

    // ──────────────────────────── Unterkünfte ────────────────────────────

    /**
     * Globaler Katalog wiederverwendbarer Unterkuenfte. Optional nach Name
     * gefiltert.
     *
     * @return list<array<string, mixed>>
     */
    public function listAccommodationCatalog(?string $query): array
    {
        return $this->accommodationRepo->findCatalog($query);
    }

    /**
     * Legt eine Unterkunft an oder verknuepft eine bereits vorhandene
     * (Katalog-)Unterkunft mit der Reise.
     *
     * @param array<string, mixed> $body
     */
    public function createAccommodation(AuthenticatedUser $user, string $tripId, array $body): array
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertTripIsActive($tripId);

        if (!$this->policy->canCreateAccommodation($user, $this->relationRepo->isParticipant($user->id, $tripId))) {
            throw new \RuntimeException('Not a participant');
        }

        // Vorhandene Unterkunft nur verknuepfen (Wiederverwendung).
        $existingId = TravelInput::nullableString($body, 'accommodationId');
        if ($existingId !== null) {
            $existing = $this->accommodationRepo->findById($existingId);
            if ($existing === null) {
                throw new \RuntimeException('Accommodation not found');
            }
            $this->accommodationRepo->linkToTrip($tripId, $existingId);
            return $this->enrichAccommodation($existing, $tripId);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name required');
        }

        $id = $this->accommodationRepo->create([
            'name' => $name,
            'description' => TravelInput::nullableString($body, 'description'),
            'address' => TravelInput::nullableString($body, 'address'),
            'OSMID' => TravelInput::nullableInt($body, 'OSMID'),
            'latitude' => TravelInput::nullableFloat($body, 'latitude'),
            'longitude' => TravelInput::nullableFloat($body, 'longitude'),
            'phone' => TravelInput::nullableString($body, 'phone'),
            'mail' => TravelInput::nullableString($body, 'mail'),
            'ishotel' => !empty($body['ishotel']) ? 1 : 0,
            'citySlug' => TravelInput::nullableString($body, 'citySlug'),
            'createdBy' => $user->id,
        ]);

        $this->accommodationRepo->linkToTrip($tripId, $id);

        $accommodation = $this->accommodationRepo->findById($id)
            ?? throw new \RuntimeException('Accommodation not found');

        return $this->enrichAccommodation($accommodation, $tripId);
    }

    /** @param array<string, mixed> $body */
    public function updateAccommodation(AuthenticatedUser $user, string $tripId, string $accommodationId, array $body): array
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertTripIsActive($tripId);

        $accommodation = $this->accommodationRepo->findByIdAndTrip($accommodationId, $tripId);
        if ($accommodation === null) {
            throw new \RuntimeException('Accommodation not found');
        }

        if (!$this->policy->canEditAccommodation(
            $user,
            $this->relationRepo->getRole($user->id, $tripId),
            $accommodation['createdBy'] ?? null,
        )) {
            throw new \RuntimeException('Not a leader');
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        foreach (['description', 'address', 'phone', 'mail', 'citySlug'] as $field) {
            if (isset($body[$field])) {
                $data[$field] = TravelInput::nullableString($body, $field);
            }
        }
        if (isset($body['OSMID'])) {
            $data['OSMID'] = TravelInput::nullableInt($body, 'OSMID');
        }
        if (isset($body['latitude'])) {
            $data['latitude'] = TravelInput::nullableFloat($body, 'latitude');
        }
        if (isset($body['longitude'])) {
            $data['longitude'] = TravelInput::nullableFloat($body, 'longitude');
        }
        if (isset($body['ishotel'])) {
            $data['ishotel'] = !empty($body['ishotel']) ? 1 : 0;
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->accommodationRepo->update($accommodationId, $data);

        $updated = $this->accommodationRepo->findById($accommodationId)
            ?? throw new \RuntimeException('Accommodation not found');

        return $this->enrichAccommodation($updated, $tripId);
    }

    /**
     * Loest eine Unterkunft von einer Reise (Loeschen der Verknuepfung),
     * ohne den globalen Katalogeintrag zu entfernen.
     */
    public function deleteAccommodation(AuthenticatedUser $user, string $tripId, string $accommodationId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            throw new \RuntimeException('Trip not found');
        }

        $this->assertCanManageTrip($user, $tripId);

        if ($this->accommodationRepo->findByIdAndTrip($accommodationId, $tripId) === null) {
            throw new \RuntimeException('Accommodation not found');
        }

        $this->accommodationRepo->unlinkFromTrip($tripId, $accommodationId);
        $this->relationRepo->clearAccommodationForTrip($tripId, $accommodationId);
    }

    /**
     * Entfernt eine Unterkunft endgueltig aus dem globalen Katalog. Nur der
     * Ersteller (oder Admin) darf das.
     */
    public function deleteAccommodationGlobally(AuthenticatedUser $user, string $accommodationId): void
    {
        $accommodation = $this->accommodationRepo->findById($accommodationId);
        if ($accommodation === null) {
            throw new \RuntimeException('Accommodation not found');
        }

        if (!$this->policy->canDeleteAccommodationGlobally($user, $accommodation['createdBy'] ?? null)) {
            throw new \RuntimeException('Accommodation delete not allowed');
        }

        $this->accommodationRepo->deleteAllLinks($accommodationId);
        $this->relationRepo->clearAccommodationEverywhere($accommodationId);
        $this->accommodationRepo->delete($accommodationId);
    }

    // ──────────────────────────── interne Helfer ────────────────────────────

    /** @param array<string, mixed> $body */
    private function createEventInternal(AuthenticatedUser $user, array $body, ?string $tripId): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name required');
        }

        $timing = $this->timingOrFail($body, !empty($body['allDay']));

        $id = $this->eventRepo->create(array_merge($timing, [
            'trip' => $tripId,
            'name' => $name,
            'description' => TravelInput::nullableString($body, 'description'),
            'hastickets' => TravelInput::boolFlag($body, 'hastickets'),
            'ticket' => TravelInput::nullableString($body, 'ticket'),
            'ticketUrl' => TravelInput::nullableString($body, 'ticketUrl'),
            'url' => TravelInput::nullableString($body, 'url'),
            'image' => $this->validatedImageFromBody($body),
            'organizer' => TravelInput::nullableString($body, 'organizer'),
            'address' => TravelInput::nullableString($body, 'address'),
            'latitude' => TravelInput::nullableFloat($body, 'latitude'),
            'longitude' => TravelInput::nullableFloat($body, 'longitude'),
            'OSMID' => TravelInput::nullableInt($body, 'OSMID'),
            'citySlug' => TravelInput::nullableString($body, 'citySlug'),
        ]));

        if ($tripId === null) {
            $this->eventRelationRepo->addParticipant($id, $user->id, TravelPolicy::ROLE_LEADER);
        } else {
            $event = $this->eventRepo->findById($id);
            if ($event !== null) {
                $this->notificationService->notifyTripEventAdded($tripId, $event);
            }
        }

        return $this->getEventById($id, $user);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $body
     */
    private function updateEventInternal(AuthenticatedUser $user, array $event, array $body): array
    {
        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = TravelInput::nullableString($body, 'description');
        }
        if (TravelInput::hasTimingFields($body)) {
            $data = array_merge($data, $this->timingOrFail($body, (int) ($event['allDay'] ?? 0) === 1));
        }
        foreach (['ticket', 'ticketUrl', 'url', 'organizer', 'address', 'citySlug'] as $field) {
            if (isset($body[$field])) {
                $data[$field] = TravelInput::nullableString($body, $field);
            }
        }
        if (array_key_exists('image', $body)) {
            $data['image'] = $this->validatedImageFromBody($body);
        }
        if (isset($body['hastickets'])) {
            $data['hastickets'] = TravelInput::boolFlag($body, 'hastickets');
        }
        if (isset($body['latitude'])) {
            $data['latitude'] = TravelInput::nullableFloat($body, 'latitude');
        }
        if (isset($body['longitude'])) {
            $data['longitude'] = TravelInput::nullableFloat($body, 'longitude');
        }
        if (isset($body['OSMID'])) {
            $data['OSMID'] = TravelInput::nullableInt($body, 'OSMID');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $changedFields = TravelInput::detectEventChanges($event, $data);
        $this->eventRepo->update($event['ID'], $data);

        if ($changedFields !== []) {
            $this->notificationService->notifyEventInfoChanged($event, $changedFields, $user->id);
        }

        return $this->getEventById($event['ID'], $user);
    }

    /** @return array<string, mixed> */
    private function findStandaloneEvent(string $eventId): array
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null || ($event['trip'] ?? null) !== null) {
            throw new \RuntimeException('Event not found');
        }

        return $event;
    }

    private function assertCanManageTrip(AuthenticatedUser $user, string $tripId): void
    {
        if (!$this->policy->canManageTrip($user, $this->relationRepo->getRole($user->id, $tripId))) {
            throw new \RuntimeException('Not a leader');
        }

        $this->assertTripIsActive($tripId);
    }

    /**
     * Operative Reise-Objekte duerfen nur an aktiven Reisen veraendert werden.
     * Planungsreisen (state 'planning') werden ausschliesslich ueber
     * /trips/planning bearbeitet und erst durch die Aktivierung operativ.
     */
    private function assertTripIsActive(string $tripId): void
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip !== null && ($trip['state'] ?? 'active') !== 'active') {
            throw new \RuntimeException('Trip not active');
        }
    }

    private function assertCanManageStandaloneEvent(AuthenticatedUser $user, string $eventId): void
    {
        $role = $this->eventRelationRepo->getRole($eventId, $user->id);
        if (!$this->policy->canManageEvent($user, true, null, $role)) {
            throw new \RuntimeException('Not a leader');
        }
    }

    /** @param array<string, mixed> $event */
    private function assertCanManageEvent(AuthenticatedUser $user, array $event): void
    {
        $tripId = $event['trip'] ?? null;
        if ($tripId !== null) {
            $this->assertCanManageTrip($user, $tripId);
            return;
        }

        $this->assertCanManageStandaloneEvent($user, $event['ID']);
    }

    private function normalizeTripTarget(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $body */
    private function timingOrFail(array $body, bool $defaultAllDay): array
    {
        try {
            return TravelInput::timingFromBody($body, $defaultAllDay);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /** @param array<string, mixed> $body */
    private function validatedImageFromBody(array $body): ?string
    {
        if (!array_key_exists('image', $body) || !TravelInput::isValidImageData($body['image'])) {
            return null;
        }

        try {
            return $this->imageService->validate((string) $body['image'], 500 * 1024, 2000, 3.5);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /** @param array<string, mixed> $trip */
    private function enrichTrip(array $trip, AuthenticatedUser $user): array
    {
        $forum = null;
        $forumId = $trip['forumId'] ?? null;
        if ($forumId !== null) {
            $forum = $this->forumRepo->findById($forumId);
            if ($forum === null) {
                $forumId = null;
            }
        }

        $trip['forumId'] = $forumId;

        if ($forum !== null) {
            $trip['forum'] = [
                'id' => $forum['id'],
                'name' => $forum['name'],
                'description' => $forum['description'],
                'image' => $forum['image'],
            ];
        } else {
            $trip['forum'] = null;
        }

        $trip['subscriptionCount'] = $this->tripSubscriptionRepo->countByTrip($trip['id']);

        $travelChat = $this->travelChatRepo->findByTripId($trip['id']);
        $trip['conversationId'] = $travelChat !== null ? $travelChat['conversationId'] : null;

        $trip['role'] = $this->relationRepo->getRole($user->id, $trip['id']);
        $trip['canEdit'] = $this->policy->canManageTrip($user, $trip['role']);

        return DateTimeValue::normalizeTimingForOutput($trip);
    }

    /** @param array<string, mixed> $event */
    private function enrichEvent(array $event, AuthenticatedUser $user): array
    {
        $event['participants'] = $this->eventRepo->findParticipantsByEvent($event['ID']);

        $travelChat = $this->travelChatRepo->findByEventId($event['ID']);
        $event['conversationId'] = $travelChat !== null ? $travelChat['conversationId'] : null;

        $tripId = $event['trip'] ?? null;
        if ($tripId !== null) {
            $role = $this->relationRepo->getRole($user->id, $tripId);
            $event['role'] = $role;
            $event['canEdit'] = $this->policy->canManageTrip($user, $role);
        } else {
            $role = $this->eventRelationRepo->getRole($event['ID'], $user->id);
            $event['role'] = $role;
            $event['canEdit'] = $this->policy->canManageEvent($user, true, null, $role);
        }

        return DateTimeValue::normalizeTimingForOutput($event);
    }

    /** @param array<string, mixed> $accommodation */
    private function enrichAccommodation(array $accommodation, string $tripId): array
    {
        $accommodation['users'] = $this->accommodationRepo->findUsersByAccommodation(
            $accommodation['ID'],
            $tripId,
        );
        return $accommodation;
    }
}
