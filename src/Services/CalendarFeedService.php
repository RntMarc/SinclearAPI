<?php

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Sinclear\Api\Repository\PtJourneyRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;

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
    public function buildFeed(string $userId, string $start, string $end, array $types): array
    {
        $this->assertValidDate($start);
        $this->assertValidDate($end);

        $items = [];
        $truncated = false;

        foreach ($types as $type) {
            $result = match ($type) {
                'calendar_event' => $this->calendarEvents($userId, $start, $end),
                'travel_event' => $this->travelEvents($userId, $start, $end),
                'trip' => $this->trips($userId, $start, $end),
                'birthday' => $this->birthdays($userId, $start, $end),
                'pt_journey' => $this->ptJourneys($userId, $start, $end),
                default => ['items' => [], 'truncated' => false],
            };
            $items = array_merge($items, $result['items']);
            $truncated = $truncated || $result['truncated'];
        }

        usort($items, fn(array $a, array $b): int => [
            $a['startDate'],
            $a['startTime'] ?? '',
            $a['type'],
            $a['id'],
        ] <=> [
            $b['startDate'],
            $b['startTime'] ?? '',
            $b['type'],
            $b['id'],
        ]);

        return [
            'data' => $items,
            'meta' => [
                'start' => $start,
                'end' => $end,
                'types' => $types,
                'count' => count($items),
                'truncated' => $truncated,
            ],
        ];
    }

    private function calendarEvents(string $userId, string $start, string $end): array
    {
        $result = $this->calendarEventService->listVisible(
            $userId,
            $start,
            $end,
            1,
            self::MAX_ITEMS_PER_SOURCE,
        );

        $items = [];
        foreach ($result['data'] as $event) {
            $item = [
                'type' => 'calendar_event',
                'id' => $event['id'],
                'title' => $event['title'] ?? null,
                'startDate' => $event['startDate'],
                'endDate' => $event['endDate'],
                'allDay' => (bool) ($event['allDay'] ?? 0),
                'detail' => $event,
            ];
            if (empty($event['allDay'])) {
                $item['startTime'] = $event['startTime'];
                $item['endTime'] = $event['endTime'];
            }
            $items[] = $item;
        }

        return [
            'items' => $items,
            'truncated' => (int) $result['meta']['total'] > self::MAX_ITEMS_PER_SOURCE,
        ];
    }

    private function travelEvents(string $userId, string $start, string $end): array
    {
        $events = $this->travelEventRepo->findVisibleInRange(
            $userId,
            $start,
            $end,
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
            $detail = $event; // dates/times already in new format
            $detail['participants'] = $participantsByEvent[$event['ID']] ?? [];

            $allDay = (bool) ($detail['allDay'] ?? 0);
            $item = [
                'type' => 'travel_event',
                'id' => $event['ID'],
                'title' => $event['name'] ?? null,
                'startDate' => $detail['startDate'],
                'endDate' => $detail['endDate'],
                'allDay' => $allDay,
                'detail' => $detail,
            ];
            if (!$allDay) {
                $item['startTime'] = $detail['startTime'] ?? $detail['endTime'];
                $item['endTime'] = $detail['endTime'] ?? $detail['startTime'];
            }
            $items[] = $item;
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    private function trips(string $userId, string $start, string $end): array
    {
        $trips = $this->tripRepo->findByParticipantInRange(
            $userId,
            $start,
            $end,
            self::MAX_ITEMS_PER_SOURCE + 1,
        );

        $truncated = count($trips) > self::MAX_ITEMS_PER_SOURCE;
        $trips = array_slice($trips, 0, self::MAX_ITEMS_PER_SOURCE);

        $items = [];
        foreach ($trips as $trip) {
            $allDay = (bool) ($trip['allDay'] ?? 1);
            $item = [
                'type' => 'trip',
                'id' => $trip['id'],
                'title' => $trip['name'] ?? null,
                'startDate' => $trip['startDate'],
                'endDate' => $trip['endDate'],
                'allDay' => $allDay,
                'detail' => $trip,
            ];
            if (!$allDay) {
                $item['startTime'] = $trip['startTime'] ?? $trip['endTime'];
                $item['endTime'] = $trip['endTime'] ?? $trip['startTime'];
            }
            $items[] = $item;
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

    private function ptJourneys(string $userId, string $start, string $end): array
    {
        // PtJourney uses datetime columns; expand date bounds to half-open datetime window
        $startDt = $start . ' 00:00:00';
        $endDt = (new DateTimeImmutable($end . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');

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

        $items = [];
        foreach ($journeys as $journey) {
            $detail = $this->formatJourney($journey);
            $detail['legs'] = $legsByJourney[$journey['id']] ?? [];

            $departure = $detail['departureTime'] ?? $detail['arrivalTime'];
            $arrival = $detail['arrivalTime'] ?? $detail['departureTime'];

            // Split datetime into date + time
            $depDate = $departure ? substr($departure, 0, 10) : $start;
            $arrDate = $arrival ? substr($arrival, 0, 10) : $end;
            $depTime = $departure ? substr($departure, 11, 8) : '00:00:00';
            $arrTime = $arrival ? substr($arrival, 11, 8) : '00:00:00';

            $item = [
                'type' => 'pt_journey',
                'id' => $journey['id'],
                'title' => trim(($journey['fromStationName'] ?? '') . ' → ' . ($journey['toStationName'] ?? '')),
                'startDate' => $depDate,
                'endDate' => $arrDate,
                'startTime' => $depTime,
                'endTime' => $arrTime,
                'allDay' => false,
                'detail' => $detail,
            ];
            $items[] = $item;
        }

        return ['items' => $items, 'truncated' => $truncated];
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

    /**
     * @param list<string> $fields
     */
    private function normalizeDatetimes(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->formatUtc($row[$field]);
            }
        }
        return $row;
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
