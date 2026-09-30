<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\TravelPlanMemberRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;

/**
 * Benachrichtigungen rund um die Reiseplanung (TravelTrip.state = 'planning').
 *
 * Empfaengerlogik:
 *   - Einladung  -> der eingeladene Nutzer
 *   - Rueckmeldung -> die Leitung (ausser der Person, die selbst geantwortet hat)
 *   - Festlegung/Aktivierung -> alle aktiven Planungsmitglieder (ausser Auslöser)
 */
final readonly class TravelPlanningNotificationService
{
    public function __construct(
        private NotificationService $notificationService,
        private TravelTripRepository $tripRepo,
        private TravelPlanMemberRepository $memberRepo,
        private UserRepository $userRepo,
    ) {}

    public function notifyInvite(string $tripId, string $invitedUserId, string $actorUserId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            return;
        }

        if ($this->userRepo->findById($invitedUserId) === null) {
            return;
        }

        $this->notificationService->create(
            userId: $invitedUserId,
            type: 'trip_planning_invite',
            title: '',
            body: '',
            data: [
                ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ['relation' => 'inviter', 'object' => 'User', 'identifier' => $actorUserId],
            ],
        );
    }

    public function notifyResponse(string $tripId, string $responderUserId, string $actorUserId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            return;
        }

        foreach ($this->memberRepo->findActiveByTrip($tripId) as $member) {
            if (($member['role'] ?? null) !== 'leader') {
                continue;
            }
            if ($member['userId'] === $responderUserId) {
                continue;
            }

            $this->notificationService->create(
                userId: $member['userId'],
                type: 'trip_planning_response',
                title: '',
                body: '',
                data: [
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                    ['relation' => 'responder', 'object' => 'User', 'identifier' => $responderUserId],
                ],
            );
        }
    }

    /**
     * @param array{relation: string, object: string, identifier: string}|null $finalizedRelation
     */
    public function notifyFinalized(string $tripId, ?array $finalizedRelation, string $actorUserId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            return;
        }

        $data = [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
        ];
        if ($finalizedRelation !== null) {
            $data[] = $finalizedRelation;
        }

        foreach ($this->activeMemberIds($tripId, $actorUserId) as $userId) {
            $this->notificationService->create(
                userId: $userId,
                type: 'trip_planning_finalized',
                title: '',
                body: '',
                data: $data,
            );
        }
    }

    public function notifyActivated(string $tripId, string $actorUserId): void
    {
        if ($this->tripRepo->findById($tripId) === null) {
            return;
        }

        foreach ($this->activeMemberIds($tripId, $actorUserId) as $userId) {
            $this->notificationService->create(
                userId: $userId,
                type: 'trip_planning_activated',
                title: '',
                body: '',
                data: [
                    ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function activeMemberIds(string $tripId, string $excludeUserId): array
    {
        $ids = [];
        foreach ($this->memberRepo->findActiveByTrip($tripId) as $member) {
            if ($member['userId'] === $excludeUserId) {
                continue;
            }
            $ids[] = $member['userId'];
        }

        return array_values(array_unique($ids));
    }
}
