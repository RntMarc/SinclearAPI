<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;

/**
 * Benachrichtigungen rund um Reisen, Reise-Events und Standalone-Events.
 *
 * Herausgeloest aus dem AdminController, damit sowohl Admin- als auch
 * Nutzer-Endpunkte dieselbe Empfaenger-/Relationslogik nutzen.
 */
final readonly class TravelNotificationService
{
    public function __construct(
        private NotificationService $notificationService,
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelRelationRepository $travelRelationRepo,
        private EventRelationRepository $eventRelationRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private UserRepository $userRepo,
    ) {}

    public function notifyTripUserAdded(string $tripId, string $addedUserId, string $actorUserId): void
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        if ($this->userRepo->findById($addedUserId) === null) {
            return;
        }

        $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        foreach ($participants as $participant) {
            $isAddedUser = $participant['id'] === $addedUserId;
            $this->notificationService->create(
                userId: $participant['id'],
                type: $isAddedUser ? 'trip_user_added' : 'trip_user_added_others',
                title: '',
                body: '',
                data: [
                    ['relation' => 'added_user', 'object' => 'User', 'identifier' => $addedUserId],
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                    ['relation' => 'added_by', 'object' => 'User', 'identifier' => $actorUserId],
                ],
            );
        }
    }

    /** @param array<string, mixed> $event */
    public function notifyEventUserAdded(array $event, string $addedUserId, string $actorUserId): void
    {
        if ($this->userRepo->findById($addedUserId) === null) {
            return;
        }

        $isTripEvent = ($event['trip'] ?? null) !== null;
        $participants = $this->eventRelationRepo->findByEvent($event['ID']);
        foreach ($participants as $participant) {
            $isAddedUser = $participant['userId'] === $addedUserId;
            $type = match (true) {
                $isTripEvent && $isAddedUser => 'trip_event_user_added',
                $isTripEvent => 'trip_event_user_added_others',
                $isAddedUser => 'standalone_event_user_added',
                default => 'standalone_event_user_added_others',
            };
            $data = [
                ['relation' => 'added_user', 'object' => 'User', 'identifier' => $addedUserId],
                ['relation' => 'event', 'object' => 'Event', 'identifier' => $event['ID']],
                ['relation' => 'added_by', 'object' => 'User', 'identifier' => $actorUserId],
            ];
            if ($isTripEvent) {
                $data[] = ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $event['trip']];
            }
            $this->notificationService->create(
                userId: $participant['userId'],
                type: $type,
                title: '',
                body: '',
                data: $data,
            );
        }
    }

    /** @param array<string, mixed> $event */
    public function notifyTripEventAdded(string $tripId, array $event): void
    {
        $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        foreach ($participants as $participant) {
            $this->notificationService->create(
                userId: $participant['id'],
                type: 'trip_event_added',
                title: '',
                body: '',
                data: [
                    ['relation' => 'event', 'object' => 'Event', 'identifier' => $event['ID']],
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ],
            );
        }
    }

    public function notifyTripAccommodationAdded(string $tripId, string $userId, string $accommodationId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            return;
        }

        if ($this->accommodationRepo->findById($accommodationId) === null) {
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

    /** @param array<string, mixed> $ticket */
    public function notifyTicketAdded(array $ticket, string $actorUserId): void
    {
        if (($ticket['type'] ?? null) === 'user') {
            return;
        }

        if (($ticket['type'] ?? null) === 'trip' && ($ticket['trip'] ?? null) !== null) {
            $participants = $this->travelRelationRepo->findParticipantsByTrip($ticket['trip']);
            foreach ($participants as $participant) {
                if ($participant['id'] === $actorUserId) {
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
                        ['relation' => 'uploaded_by', 'object' => 'User', 'identifier' => $actorUserId],
                    ],
                );
            }

            return;
        }

        if (($ticket['type'] ?? null) === 'event' && ($ticket['event'] ?? null) !== null) {
            $event = $this->eventRepo->findById($ticket['event']);
            if ($event === null) {
                return;
            }

            $isTripEvent = ($event['trip'] ?? null) !== null;
            $type = $isTripEvent ? 'trip_event_ticket_added' : 'standalone_event_ticket_added';

            $participants = $this->eventRelationRepo->findByEvent($ticket['event']);
            foreach ($participants as $participant) {
                if ($participant['userId'] === $actorUserId) {
                    continue;
                }
                $data = [
                    ['relation' => 'ticket', 'object' => 'Ticket', 'identifier' => $ticket['ID']],
                    ['relation' => 'event', 'object' => 'Event', 'identifier' => $ticket['event']],
                    ['relation' => 'uploaded_by', 'object' => 'User', 'identifier' => $actorUserId],
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

    /** @param list<string> $changedFields */
    public function notifyTripInfoChanged(string $tripId, array $changedFields, string $actorUserId): void
    {
        $fieldsString = implode(', ', $changedFields);

        $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        foreach ($participants as $participant) {
            $this->notificationService->create(
                userId: $participant['id'],
                type: 'trip_info_changed',
                title: '',
                body: '',
                data: [
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                    ['relation' => 'changed_by', 'object' => 'User', 'identifier' => $actorUserId],
                    ['relation' => 'changed_fields', 'object' => 'FieldList', 'identifier' => $fieldsString],
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string> $changedFields
     */
    public function notifyEventInfoChanged(array $event, array $changedFields, string $actorUserId): void
    {
        $fieldsString = implode(', ', $changedFields);
        $isTripEvent = ($event['trip'] ?? null) !== null;
        $type = $isTripEvent ? 'trip_event_info_changed' : 'standalone_event_info_changed';

        $participants = $this->eventRelationRepo->findByEvent($event['ID']);
        foreach ($participants as $participant) {
            $data = [
                ['relation' => 'event', 'object' => 'Event', 'identifier' => $event['ID']],
                ['relation' => 'changed_by', 'object' => 'User', 'identifier' => $actorUserId],
                ['relation' => 'changed_fields', 'object' => 'FieldList', 'identifier' => $fieldsString],
            ];
            if ($isTripEvent) {
                $data[] = ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $event['trip']];
            }
            $this->notificationService->create(
                userId: $participant['userId'],
                type: $type,
                title: '',
                body: '',
                data: $data,
            );
        }
    }

    public function notifyTripSubscriptionAdded(string $tripId, string $subscriptionId): void
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

    public function notifyTripRoleChanged(string $tripId, string $changedUserId, bool $appointed, string $actorUserId): void
    {
        $participants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        foreach ($participants as $participant) {
            $isChangedUser = $participant['id'] === $changedUserId;
            $type = match (true) {
                $isChangedUser && $appointed => 'trip_leader_appointed',
                $isChangedUser => 'trip_leader_removed',
                $appointed => 'trip_leader_appointed_others',
                default => 'trip_leader_removed_others',
            };
            $this->notificationService->create(
                userId: $participant['id'],
                type: $type,
                title: '',
                body: '',
                data: [
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                    ['relation' => 'changed_user', 'object' => 'User', 'identifier' => $changedUserId],
                    ['relation' => 'changed_by', 'object' => 'User', 'identifier' => $actorUserId],
                ],
            );
        }
    }

    public function notifyStandaloneEventRoleChanged(string $eventId, string $changedUserId, bool $appointed, string $actorUserId): void
    {
        $participants = $this->eventRelationRepo->findByEvent($eventId);
        foreach ($participants as $participant) {
            $isChangedUser = $participant['userId'] === $changedUserId;
            $type = match (true) {
                $isChangedUser && $appointed => 'standalone_event_leader_appointed',
                $isChangedUser => 'standalone_event_leader_removed',
                $appointed => 'standalone_event_leader_appointed_others',
                default => 'standalone_event_leader_removed_others',
            };
            $this->notificationService->create(
                userId: $participant['userId'],
                type: $type,
                title: '',
                body: '',
                data: [
                    ['relation' => 'event', 'object' => 'Event', 'identifier' => $eventId],
                    ['relation' => 'changed_user', 'object' => 'User', 'identifier' => $changedUserId],
                    ['relation' => 'changed_by', 'object' => 'User', 'identifier' => $actorUserId],
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    public function notifyEventConverted(array $event, ?string $fromTripId, ?string $toTripId, string $actorUserId): void
    {
        $recipients = [];
        foreach ($this->eventRelationRepo->findByEvent($event['ID']) as $participant) {
            $recipients[$participant['userId']] = true;
        }
        foreach ([$fromTripId, $toTripId] as $tripId) {
            if ($tripId === null) {
                continue;
            }
            foreach ($this->travelRelationRepo->findParticipantsByTrip($tripId) as $participant) {
                $recipients[$participant['id']] = true;
            }
        }

        $type = $toTripId !== null ? 'standalone_event_converted_to_trip' : 'trip_event_converted_to_standalone';

        foreach (array_keys($recipients) as $userId) {
            if ($userId === $actorUserId) {
                continue;
            }

            $data = [
                ['relation' => 'event', 'object' => 'Event', 'identifier' => $event['ID']],
                ['relation' => 'converted_by', 'object' => 'User', 'identifier' => $actorUserId],
            ];
            if ($toTripId !== null) {
                $data[] = ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $toTripId];
            }

            $this->notificationService->create(
                userId: $userId,
                type: $type,
                title: '',
                body: '',
                data: $data,
            );
        }
    }
}
