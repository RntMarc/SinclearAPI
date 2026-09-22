<?php

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;

final readonly class CalendarEventService
{
    public function __construct(
        private CalendarEventRepository $eventRepo,
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

        return $event;
    }

    public function update(string $id, string $userId, array $data): array
    {
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if (!$this->canModify($userId, $event)) {
            throw new \RuntimeException('Forbidden');
        }

        // Merge incoming data with existing to validate cross-field constraints
        $merged = array_merge($event, $data);
        $this->validateDateTimeConsistency($merged);

        $this->eventRepo->update($id, $data);

        $event = $this->eventRepo->findById($id);
        $event = $this->enrich($event);

        return $event;
    }

    private function validateDateTimeConsistency(array $event): void
    {
        $allDay = (bool) ($event['allDay'] ?? 0);

        if ($allDay) {
            // All-day: dates required, times must be empty
            if (empty($event['startDate']) || empty($event['endDate'])) {
                throw new \RuntimeException('Invalid date');
            }
            if ($event['startDate'] > $event['endDate']) {
                throw new \RuntimeException('Invalid time range');
            }
            if (!empty($event['startTime']) || !empty($event['endTime'])) {
                throw new \RuntimeException('Invalid time');
            }
        } else {
            // Timed: all four fields required
            if (empty($event['startDate']) || empty($event['endDate']) || empty($event['startTime']) || empty($event['endTime'])) {
                throw new \RuntimeException('Invalid datetime');
            }
            $startMoment = $event['startDate'] . ' ' . $event['startTime'];
            $endMoment = $event['endDate'] . ' ' . $event['endTime'];
            if ($startMoment >= $endMoment) {
                throw new \RuntimeException('Invalid time range');
            }
        }
    }

    public function delete(string $id, string $userId): void
    {
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }
        if (!$this->canModify($userId, $event)) {
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
        if (!$this->canModify($actorId, $event)) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->addParticipant($eventId, $participantId);

        $event = $this->enrich($event);

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
        if (!$this->canModify($actorId, $event)) {
            throw new \RuntimeException('Forbidden');
        }

        $this->eventRepo->removeParticipant($eventId, $participantId);
    }

    private function canModify(string $userId, array $event): bool
    {
        if ($event['creatorId'] === $userId) {
            return true;
        }
        return $this->eventRepo->isParticipant($event['id'], $userId);
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
        // Normalize date/time fields for response
        if (isset($event['startDate'])) {
            $event['startDate'] = $this->formatDate($event['startDate']);
        }
        if (isset($event['endDate'])) {
            $event['endDate'] = $this->formatDate($event['endDate']);
        }
        if (isset($event['startTime'])) {
            $event['startTime'] = $this->formatTime($event['startTime']);
        }
        if (isset($event['endTime'])) {
            $event['endTime'] = $this->formatTime($event['endTime']);
        }
        // For all-day events, omit time fields (they are NULL in DB)
        if (($event['allDay'] ?? 0) === 1) {
            unset($event['startTime'], $event['endTime']);
        }

        foreach (['createdAt', 'updatedAt'] as $field) {
            if (isset($event[$field])) {
                $event[$field] = (new DateTimeImmutable($event[$field], new DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s');
            }
        }

        $event['participants'] = $this->eventRepo->findParticipantsByEvent($event['id']);
        return $event;
    }

    private function formatDate(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function formatTime(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('H:i:s');
    }
}
