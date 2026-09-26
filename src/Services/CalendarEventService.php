<?php

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Support\DateTimeValue;

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

        $data = $this->normalizeTiming($event, $data);

        $this->eventRepo->update($id, $data);

        $event = $this->eventRepo->findById($id);
        $event = $this->enrich($event);

        return $event;
    }

    /**
     * Bringt die Timing-Felder in eine konsistente Form: ganztägige Events
     * tragen nur startDate/endDate, getaktete nur startAt/endAt. Die jeweils
     * andere Feldgruppe wird explizit geleert, damit ein Umschalten des
     * allDay-Flags keine veralteten Werte hinterlaesst.
     */
    private function normalizeTiming(array $event, array $data): array
    {
        $allDay = array_key_exists('allDay', $data)
            ? (bool) $data['allDay']
            : ((int) ($event['allDay'] ?? 0) === 1);

        if ($allDay) {
            $rawStart = array_key_exists('startDate', $data) ? $data['startDate'] : ($event['startDate'] ?? null);
            $rawEnd = array_key_exists('endDate', $data) ? $data['endDate'] : ($event['endDate'] ?? null);
            if ($rawStart === null || $rawStart === '' || $rawEnd === null || $rawEnd === '') {
                throw new \RuntimeException('Invalid date');
            }

            $start = DateTimeValue::parseDate((string) $rawStart);
            $end = DateTimeValue::parseDate((string) $rawEnd);
            if ($end < $start) {
                throw new \RuntimeException('Invalid time range');
            }

            return array_merge($data, [
                'allDay' => 1,
                'startDate' => DateTimeValue::formatDate($start),
                'endDate' => DateTimeValue::formatDate($end),
                'startAt' => null,
                'endAt' => null,
            ]);
        }

        $rawStart = array_key_exists('startAt', $data)
            ? $data['startAt']
            : DateTimeValue::fromDatabase($event['startAt'] ?? null);
        $rawEnd = array_key_exists('endAt', $data)
            ? $data['endAt']
            : DateTimeValue::fromDatabase($event['endAt'] ?? null);
        if ($rawStart === null || $rawStart === '' || $rawEnd === null || $rawEnd === '') {
            throw new \RuntimeException('Invalid datetime');
        }

        $start = $rawStart instanceof DateTimeImmutable ? $rawStart : DateTimeValue::parseInstant((string) $rawStart);
        $end = $rawEnd instanceof DateTimeImmutable ? $rawEnd : DateTimeValue::parseInstant((string) $rawEnd);
        if ($end <= $start) {
            throw new \RuntimeException('Invalid time range');
        }

        return array_merge($data, [
            'allDay' => 0,
            'startAt' => DateTimeValue::toDatabase($start),
            'endAt' => DateTimeValue::toDatabase($end),
            'startDate' => null,
            'endDate' => null,
        ]);
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
        string $rangeTimezone,
        int $page,
        int $limit,
    ): array {
        $result = $this->eventRepo->findAllVisible($userId, $start, $end, $rangeTimezone, $page, $limit);
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

    /**
     * Normalisiert die Ausgabe: getaktete Events als RFC 3339 in ihrer
     * Zeitzone, ganztägige als ziviler Datumsbereich, jeweils inkl. `timezone`.
     */
    private function enrich(array $event): array
    {
        $event = DateTimeValue::normalizeTimingForOutput($event);

        foreach (['createdAt', 'updatedAt'] as $field) {
            $instant = DateTimeValue::fromDatabase($event[$field] ?? null);
            if ($instant !== null) {
                $event[$field] = DateTimeValue::formatInstant($instant, new DateTimeZone('UTC'));
            }
        }

        $event['participants'] = $this->eventRepo->findParticipantsByEvent($event['id']);
        return $event;
    }
}
