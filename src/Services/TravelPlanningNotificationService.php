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
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        if ($this->userRepo->findById($invitedUserId) === null) {
            return;
        }

        $tripName = trim((string) ($trip['name'] ?? ''));
        $inviter = $this->userRepo->findById($actorUserId);
        $inviterName = trim((string) ($inviter['displayName'] ?? ''));

        $body = match (true) {
            $inviterName !== '' && $tripName !== '' =>
                $inviterName . ' hat dich zur Reiseplanung „' . $tripName . '“ eingeladen.',
            $tripName !== '' =>
                'Du wurdest zur Reiseplanung „' . $tripName . '“ eingeladen.',
            default => '',
        };

        $this->notificationService->create(
            userId: $invitedUserId,
            type: 'trip_planning_invite',
            title: '',
            body: $body,
            data: [
                ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
                ['relation' => 'inviter', 'object' => 'User', 'identifier' => $actorUserId],
            ],
        );
    }

    public function notifyResponse(string $tripId, string $responderUserId, string $actorUserId): void
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        $tripName = trim((string) ($trip['name'] ?? ''));
        $responder = $this->userRepo->findById($responderUserId);
        $responderName = trim((string) ($responder['displayName'] ?? ''));

        $body = match (true) {
            $responderName !== '' && $tripName !== '' =>
                $responderName . ' hat sich zur Reiseplanung „' . $tripName . '“ zurückgemeldet.',
            $responderName !== '' =>
                $responderName . ' hat sich zur Reiseplanung zurückgemeldet.',
            $tripName !== '' =>
                'Neue Rückmeldung in der Reiseplanung „' . $tripName . '“.',
            default => '',
        };

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
                body: $body,
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
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        $data = [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => $tripId],
        ];
        if ($finalizedRelation !== null) {
            $data[] = $finalizedRelation;
        }

        $tripName = trim((string) ($trip['name'] ?? ''));
        $what = match ($finalizedRelation['relation'] ?? null) {
            'date_option' => 'Der Zeitraum',
            'accommodation_option' => 'Die Unterkunft',
            'event' => 'Ein Programmpunkt',
            default => 'Eine Entscheidung',
        };
        $body = $tripName !== ''
            ? $what . ' für die Reiseplanung „' . $tripName . '“ wurde festgelegt.'
            : $what . ' in der Reiseplanung wurde festgelegt.';

        foreach ($this->activeMemberIds($tripId, $actorUserId) as $userId) {
            $this->notificationService->create(
                userId: $userId,
                type: 'trip_planning_finalized',
                title: '',
                body: $body,
                data: $data,
            );
        }
    }

    public function notifyActivated(string $tripId, string $actorUserId): void
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return;
        }

        $tripName = trim((string) ($trip['name'] ?? ''));
        $body = $tripName !== ''
            ? 'Die Planung für „' . $tripName . '“ wurde abgeschlossen und die Reise aktiviert.'
            : '';

        foreach ($this->activeMemberIds($tripId, $actorUserId) as $userId) {
            $this->notificationService->create(
                userId: $userId,
                type: 'trip_planning_activated',
                title: '',
                body: $body,
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
