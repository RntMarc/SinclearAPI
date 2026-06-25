<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;

final readonly class CalendarEventService
{
    public function __construct(
        private CalendarEventRepository $eventRepo,
        private NotificationService $notificationService,
        private CloseFriendRepository $closeFriendRepo,
    ) {}

    public function create(string $userId, array $data): array
    {
        $eventId = $this->eventRepo->create($userId, $data);

        $participantIds = $data['participants'] ?? [];

        foreach ($participantIds as $participantId) {
            if ($participantId !== $userId) {
                $this->eventRepo->addParticipant($eventId, $participantId);
            }
        }

        $event = $this->eventRepo->findById($eventId);
        $event = $this->enrich($event);

        $this->notifyParticipants(
            $participantIds,
            'calendar.event_created',
            $event,
            $userId,
        );

        return $event;
    }

    public function update(string $id, string $userId, array $data): array
    {
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if ($event['creatorId'] !== $userId) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->update($id, $data);

        $event = $this->eventRepo->findById($id);
        $event = $this->enrich($event);

        $participantIds = $this->eventRepo->findParticipantIdsByEvent($id);
        $this->notifyParticipants(
            $participantIds,
            'calendar.event_updated',
            $event,
            $userId,
        );

        return $event;
    }

    public function delete(string $id, string $userId): void
    {
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if ($event['creatorId'] !== $userId) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->delete($id);
    }

    public function get(string $id, string $userId): array
    {
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        if (!$this->canSee($userId, $event)) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrich($event);
    }

    public function listVisible(
        string $userId,
        ?string $start,
        ?string $end,
        int $page,
        int $limit,
    ): array {
        $result = $this->eventRepo->findAllVisible($userId, $start, $end, $page, $limit);
        $result['data'] = array_map(fn(array $e) => $this->enrich($e), $result['data']);
        return $result;
    }

    public function addParticipant(string $eventId, string $actorId, string $participantId): array
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if ($event['creatorId'] !== $actorId) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->addParticipant($eventId, $participantId);

        $event = $this->enrich($event);

        if ($participantId !== $actorId) {
            $this->notificationService->createNotification(
                userId: $participantId,
                code: 'calendar.participant_added',
                payload: [
                    'calendarEventId' => $eventId,
                    'title' => $event['title'],
                ],
            );
        }

        return [
            'calendarEventId' => $eventId,
            'userId' => $participantId,
        ];
    }

    public function removeParticipant(string $eventId, string $actorId, string $participantId): void
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if ($event['creatorId'] !== $actorId) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->removeParticipant($eventId, $participantId);
    }

    private function canSee(string $userId, array $event): bool
    {
        if ($event['creatorId'] === $userId) {
            return true;
        }

        if ($this->eventRepo->isParticipant($event['id'], $userId)) {
            return true;
        }

        $visibility = (int) $event['visibility'];

        if ($visibility === 1) {
            return true;
        }

        if ($visibility === 2 && $this->closeFriendRepo->isCloseFriend($event['creatorId'], $userId)) {
            return true;
        }

        return false;
    }

    private function enrich(array $event): array
    {
        $event['participants'] = $this->eventRepo->findParticipantsByEvent($event['id']);
        return $event;
    }

    private function notifyParticipants(
        array $participantIds,
        string $code,
        array $event,
        string $actorId,
    ): void {
        foreach ($participantIds as $pid) {
            if ($pid !== $actorId) {
                $this->notificationService->createNotification(
                    userId: $pid,
                    code: $code,
                    payload: [
                        'calendarEventId' => $event['id'],
                        'title' => $event['title'],
                    ],
                );
            }
        }
    }
}
