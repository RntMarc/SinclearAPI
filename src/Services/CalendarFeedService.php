<?php

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Sinclear\Api\Repository\PtJourneyRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Support\DateTimeValue;

final readonly class CalendarFeedService
{
    public const int MAX_ITEMS_PER_SOURCE = 500;

    public const array SUPPORTED_TYPES = [
        'calendar_event',
        'travel_event',
        'trip',
        'birthday',
        'pt_journey',
    ];

    public function __construct(
        private CalendarEventService $calendarEventService,
        private TravelEventRepository $travelEventRepo,
        private TravelTripRepository $tripRepo,
        private PtJourneyRepository $ptJourneyRepo,
        private UserRepository $userRepo,
    ) {}

    /**
     * @param list<string> $types
     */
    public function buildFeed(
        string $userId,
        string $start,
        string $end,
        array $types,
        string $rangeTimezone = 'UTC',
    ): array {
        $this->assertValidDate($start);
        $this->assertValidDate($end);
        DateTimeValue::assertTimeZone($rangeTimezone);

        $items = [];
        $truncated = false;

        foreach ($types as $type) {
            $result = match ($type) {
                'calendar_event' => $this->calendarEvents($userId, $start, $end, $rangeTimezone),
                'travel_event' => $this->travelEvents($userId, $start, $end, $rangeTimezone),
                'trip' => $this->trips($userId, $start, $end, $rangeTimezone),
                'birthday' => $this->birthdays($userId, $start, $end),
                'pt_journey' => $this->ptJourneys($userId, $start, $end, $rangeTimezone),
                default => ['items' => [], 'truncated' => false],
            };
            $items = array_merge($items, $result['items']);
            $truncated = $truncated || $result['truncated'];
        }

        usort($items, fn(array $a, array $b): int => [
            self::sortKey($a),
            $a['type'],
            $a['id'],
        ] <=> [
            self::sortKey($b),
            $b['type'],
            $b['id'],
        ]);

        return [
            'data' => $items,
            'meta' => [
                'start' => $start,
                'end' => $end,
                'timezone' => $rangeTimezone,
                'types' => $types,
                'count' => count($items),
                'truncated' => $truncated,
            ],
        ];
    }

    private function calendarEvents(string $userId, string $start, string $end, string $rangeTimezone): array
    {
        $result = $this->calendarEventService->listVisible(
            $userId,
            $start,
            $end,
            $rangeTimezone,
            1,
            self::MAX_ITEMS_PER_SOURCE,
        );

        $items = [];
        foreach ($result['data'] as $event) {
            $items[] = $this->eventItem(
                type: 'calendar_event',
                id: $event['id'],
                title: $event['title'] ?? null,
                timed: !$event['allDay'],
                startDate: $event['startDate'] ?? null,
                endDate: $event['endDate'] ?? null,
                startAt: $event['startAt'] ?? null,
                endAt: $event['endAt'] ?? null,
                timezone: $event['timezone'] ?? 'UTC',
                detail: $event,
            );
        }

        return [
            'items' => $items,
            'truncated' => (int) $result['meta']['total'] > self::MAX_ITEMS_PER_SOURCE,
        ];
    }

    private function travelEvents(string $userId, string $start, string $end, string $rangeTimezone): array
    {
        $events = $this->travelEventRepo->findVisibleInRange(
            $userId,
            $start,
            $end,
            $rangeTimezone,
            self::MAX_ITEMS_PER_SOURCE + 1,
        );

        $truncated = count($events) > self::MAX_ITEMS_PER_SOURCE;
        $events = array_slice($events, 0, self::MAX_ITEMS_PER_SOURCE);

        $participantsByEvent = [];
        foreach ($this->travelEventRepo->findParticipantsByEventIds(array_column($events, 'ID')) as $row) {
            $participantsByEvent[$row['eventId']][] = [
                'id' => $row['id'],
                'displayName' => $row['displayName'],
                'image' => $row['image'],
            ];
        }

        $items = [];
        foreach ($events as $event) {
            $detail = DateTimeValue::normalizeTimingForOutput($event);
            $detail['participants'] = $participantsByEvent[$event['ID']] ?? [];

            $items[] = $this->eventItem(
                type: 'travel_event',
                id: $event['ID'],
                title: $event['name'] ?? null,
                timed: !$detail['allDay'],
                startDate: $detail['startDate'] ?? null,
                endDate: $detail['endDate'] ?? null,
                startAt: $detail['startAt'] ?? null,
                endAt: $detail['endAt'] ?? null,
                timezone: $detail['timezone'] ?? 'UTC',
                detail: $detail,
            );
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    private function trips(string $userId, string $start, string $end, string $rangeTimezone): array
    {
        $trips = $this->tripRepo->findByParticipantInRange(
            $userId,
            $start,
            $end,
            $rangeTimezone,
            self::MAX_ITEMS_PER_SOURCE + 1,
        );

        $truncated = count($trips) > self::MAX_ITEMS_PER_SOURCE;
        $trips = array_slice($trips, 0, self::MAX_ITEMS_PER_SOURCE);

        $items = [];
        foreach ($trips as $trip) {
            $detail = DateTimeValue::normalizeTimingForOutput($trip);

            $items[] = $this->eventItem(
                type: 'trip',
                id: $trip['id'],
                title: $trip['name'] ?? null,
                timed: !$detail['allDay'],
                startDate: $detail['startDate'] ?? null,
                endDate: $detail['endDate'] ?? null,
                startAt: $detail['startAt'] ?? null,
                endAt: $detail['endAt'] ?? null,
                timezone: $detail['timezone'] ?? 'UTC',
                detail: $detail,
            );
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    private function birthdays(string $userId, string $start, string $end): array
    {
        $items = [];
        $truncated = false;

        foreach ($this->userRepo->findBirthdayCandidates($userId) as $candidate) {
            if (!$this->canSeeBirthday($userId, $candidate)) {
                continue;
            }

            $birthday = $candidate['birthday'];
            $monthDay = substr($birthday, 5, 5);
            if (strlen($monthDay) !== 5) {
                continue;
            }

            $year = (int) substr($start, 0, 4);
            $lastYear = (int) substr($end, 0, 4);

            for (; $year <= $lastYear; $year++) {
                $occurrence = sprintf('%04d-%s', $year, $monthDay);
                $occurrenceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $occurrence);
                if ($occurrenceDate === false || $occurrenceDate->format('m-d') !== $monthDay) {
                    continue;
                }
                if ($occurrence < $start || $occurrence > $end) {
                    continue;
                }
                if (count($items) >= self::MAX_ITEMS_PER_SOURCE) {
                    $truncated = true;
                    break 2;
                }

                $items[] = [
                    'type' => 'birthday',
                    'id' => $occurrence . '-' . $candidate['id'],
                    'title' => 'Geburtstag: ' . $candidate['displayName'],
                    'startDate' => $occurrence,
                    'endDate' => $occurrence,
                    'allDay' => true,
                    'timezone' => 'UTC',
                    'detail' => [
                        'userId' => $candidate['id'],
                        'displayName' => $candidate['displayName'],
                        'image' => $candidate['image'],
                        'birthday' => $birthday,
                        'occurrenceDate' => $occurrence,
                    ],
                ];
            }
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    private function ptJourneys(string $userId, string $start, string $end, string $rangeTimezone): array
    {
        // PtJourney uses datetime columns; expand date bounds to half-open datetime window
        $startDt = DateTimeValue::civilDayStartUtc($start, $rangeTimezone);
        $endDt = DateTimeValue::civilDayEndUtcExclusive($end, $rangeTimezone);

        $journeys = $this->ptJourneyRepo->findByParticipantInRange(
            $userId,
            $startDt,
            $endDt,
            self::MAX_ITEMS_PER_SOURCE + 1,
        );

        $truncated = count($journeys) > self::MAX_ITEMS_PER_SOURCE;
        $journeys = array_slice($journeys, 0, self::MAX_ITEMS_PER_SOURCE);

        $legsByJourney = [];
        foreach ($this->ptJourneyRepo->findLegsByJourneyIds(array_column($journeys, 'id')) as $leg) {
            $legsByJourney[$leg['journeyId']][] = $this->formatLeg($leg);
        }

        $utc = new DateTimeZone('UTC');
        $items = [];
        foreach ($journeys as $journey) {
            $detail = $this->formatJourney($journey);
            $detail['legs'] = $legsByJourney[$journey['id']] ?? [];

            $departure = DateTimeValue::fromDatabase($detail['departureTime']);
            $arrival = DateTimeValue::fromDatabase($detail['arrivalTime']);

            $item = [
                'type' => 'pt_journey',
                'id' => $journey['id'],
                'title' => trim(($journey['fromStationName'] ?? '') . ' → ' . ($journey['toStationName'] ?? '')),
                'allDay' => false,
                'timezone' => 'UTC',
                'startAt' => $departure !== null ? DateTimeValue::formatInstant($departure, $utc) : null,
                'endAt' => $arrival !== null ? DateTimeValue::formatInstant($arrival, $utc) : null,
                'detail' => $detail,
            ];
            $items[] = $item;
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * Baut ein Feed-Item. Getaktete Eintraege tragen `startAt`/`endAt`,
     * ganztägige `startDate`/`endDate`; beide immer `allDay` und `timezone`.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function eventItem(
        string $type,
        string $id,
        ?string $title,
        bool $timed,
        ?string $startDate,
        ?string $endDate,
        ?string $startAt,
        ?string $endAt,
        string $timezone,
        array $detail,
    ): array {
        $item = [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'allDay' => !$timed,
            'timezone' => $timezone,
            'detail' => $detail,
        ];

        if ($timed) {
            $item['startAt'] = $startAt;
            $item['endAt'] = $endAt;
        } else {
            $item['startDate'] = $startDate;
            $item['endDate'] = $endDate;
        }

        return $item;
    }

    /**
     * Chronologischer Sortierschluessel: ganztägige Eintraege am Tagesbeginn,
     * getaktete auf ihrem UTC-Instant.
     *
     * @param array<string, mixed> $item
     */
    private static function sortKey(array $item): string
    {
        if (($item['allDay'] ?? false) === true) {
            return ($item['startDate'] ?? '9999-12-31') . ' 00:00:00';
        }

        $startAt = $item['startAt'] ?? null;
        if (is_string($startAt) && $startAt !== '') {
            try {
                return DateTimeValue::toDatabase(DateTimeValue::parseInstant($startAt));
            } catch (\InvalidArgumentException) {
            }
        }

        return ($item['startDate'] ?? '9999-12-31') . ' 00:00:00';
    }

    private function canSeeBirthday(string $userId, array $candidate): bool
    {
        if ($candidate['id'] === $userId) {
            return true;
        }

        $visibility = (int) $candidate['birthdayVisibility'];

        if ($visibility === 0) {
            return false;
        }

        if ($visibility === 1) {
            return true;
        }

        if ($visibility === 2 && (bool) $candidate['isCloseFriend']) {
            return true;
        }

        return false;
    }

    private function formatJourney(array $journey): array
    {
        return [
            'id' => $journey['id'],
            'tripId' => $journey['tripId'] ?? null,
            'creatorId' => $journey['creatorId'],
            'fromStationId' => $journey['fromStationId'],
            'fromStationName' => $journey['fromStationName'],
            'toStationId' => $journey['toStationId'],
            'toStationName' => $journey['toStationName'],
            'departureTime' => $this->formatUtc($journey['departureTime']),
            'arrivalTime' => $this->formatUtc($journey['arrivalTime']),
            'duration' => (int) $journey['duration'],
            'transfers' => (int) $journey['transfers'],
            'chosenAt' => $this->formatUtc($journey['chosenAt']),
            'createdAt' => $this->formatUtc($journey['createdAt']),
        ];
    }

    private function formatLeg(array $leg): array
    {
        return [
            'id' => $leg['id'],
            'journeyId' => $leg['journeyId'],
            'legIndex' => (int) $leg['legIndex'],
            'mode' => $leg['mode'],
            'lineName' => $leg['lineName'],
            'lineProduct' => $leg['lineProduct'],
            'fromStationId' => $leg['fromStationId'],
            'fromStationName' => $leg['fromStationName'],
            'toStationId' => $leg['toStationId'],
            'toStationName' => $leg['toStationName'],
            'tripId' => $leg['tripId'],
            'plannedDeparture' => $this->formatUtc($leg['plannedDeparture']),
            'plannedArrival' => $this->formatUtc($leg['plannedArrival']),
            'actualDeparture' => $this->formatUtc($leg['actualDeparture']),
            'actualArrival' => $this->formatUtc($leg['actualArrival']),
            'departureDelay' => $leg['departureDelay'] !== null ? (int) $leg['departureDelay'] : null,
            'arrivalDelay' => $leg['arrivalDelay'] !== null ? (int) $leg['arrivalDelay'] : null,
            'departurePlatform' => $leg['departurePlatform'],
            'arrivalPlatform' => $leg['arrivalPlatform'],
            'cancelled' => (bool) $leg['cancelled'],
            'realTimeState' => $leg['realTimeState'],
        ];
    }

    private function formatUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function assertValidDate(string $value): void
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new RuntimeException('Invalid date');
        }
    }
}
