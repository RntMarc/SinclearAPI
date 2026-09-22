<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\CalendarFeedService;

final readonly class CalendarEventController
{
    private const array ERROR_MAP = [
        'Event not found' => ['event_not_found', 404],
        'Forbidden' => ['forbidden', 403],
        'Invalid datetime' => ['invalid_datetime', 400],
        'Invalid date' => ['invalid_date', 400],
        'Invalid time' => ['invalid_time', 400],
    ];

    public function __construct(
        private CalendarEventService $calendarService,
        private CalendarFeedService $calendarFeedService,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $title = trim((string) ($body['title'] ?? ''));
        $description = !empty($body['description']) ? trim((string) $body['description']) : null;
        $allDay = !empty($body['allDay']) ? (bool) $body['allDay'] : false;
        $startDate = trim((string) ($body['startDate'] ?? ''));
        $endDate = trim((string) ($body['endDate'] ?? ''));
        $startTime = trim((string) ($body['startTime'] ?? ''));
        $endTime = trim((string) ($body['endTime'] ?? ''));
        $visibility = isset($body['visibility']) ? (int) $body['visibility'] : 0;
        $participants = isset($body['participants']) && is_array($body['participants'])
            ? $body['participants']
            : [];

        if ($title === '') {
            return ResponseFactory::json(['error' => 'title_required'], 400, $response);
        }
        if ($visibility < 0 || $visibility > 2) {
            return ResponseFactory::json(['error' => 'invalid_visibility'], 400, $response);
        }

        if ($allDay) {
            if ($startDate === '' || $endDate === '') {
                return ResponseFactory::json(['error' => 'date_required'], 400, $response);
            }
            // Validate date format YYYY-MM-DD
            if (!$this->assertValidDate($startDate) || !$this->assertValidDate($endDate)) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            // Inclusive end: startDate <= endDate (single-day event allowed)
            if ($startDate > $endDate) {
                return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
            }
            // allDay events must not have time fields
            if ($startTime !== '' || $endTime !== '') {
                return ResponseFactory::json(['error' => 'time_forbidden'], 400, $response);
            }
        } else {
            // Timed events: all four fields required (date + time)
            if ($startDate === '' || $endDate === '' || $startTime === '' || $endTime === '') {
                return ResponseFactory::json(['error' => 'time_required'], 400, $response);
            }
            if (!$this->assertValidDate($startDate) || !$this->assertValidDate($endDate)) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            if (!$this->assertValidTime($startTime) || !$this->assertValidTime($endTime)) {
                return ResponseFactory::json(['error' => 'invalid_time'], 400, $response);
            }
            // Combined moment: end must be strictly after start
            $startMoment = $startDate . ' ' . $startTime;
            $endMoment = $endDate . ' ' . $endTime;
            if ($startMoment >= $endMoment) {
                return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
            }
        }

        try {
            $event = $this->calendarService->create($user->id, [
                'title' => $title,
                'description' => $description,
                'allDay' => $allDay,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'startTime' => $allDay ? null : $startTime,
                'endTime' => $allDay ? null : $endTime,
                'visibility' => $visibility,
                'participants' => $participants,
            ]);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }

        return ResponseFactory::json(['data' => $event], 201, $response);
    }

    private function assertValidDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    private function assertValidTime(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!H:i:s', $value);
        return $dt !== false && $dt->format('H:i:s') === $value;
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $data = [];

        if (isset($body['title'])) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return ResponseFactory::json(['error' => 'title_required'], 400, $response);
            }
            $data['title'] = $title;
        }

        if (isset($body['description'])) {
            $data['description'] = $body['description'] !== null
                ? trim((string) $body['description'])
                : null;
        }

        // allDay flag handling
        $hasAllDay = array_key_exists('allDay', $body);
        if ($hasAllDay) {
            $data['allDay'] = (bool) $body['allDay'];
        }

        $hasStartDate = array_key_exists('startDate', $body);
        $hasEndDate = array_key_exists('endDate', $body);
        $hasStartTime = array_key_exists('startTime', $body);
        $hasEndTime = array_key_exists('endTime', $body);

        if ($hasStartDate) {
            $startDate = trim((string) $body['startDate']);
            if ($startDate !== '' && !$this->assertValidDate($startDate)) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            $data['startDate'] = $startDate;
        }
        if ($hasEndDate) {
            $endDate = trim((string) $body['endDate']);
            if ($endDate !== '' && !$this->assertValidDate($endDate)) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            $data['endDate'] = $endDate;
        }
        if ($hasStartTime) {
            $startTime = trim((string) $body['startTime']);
            if ($startTime !== '' && !$this->assertValidTime($startTime)) {
                return ResponseFactory::json(['error' => 'invalid_time'], 400, $response);
            }
            $data['startTime'] = $startTime;
        }
        if ($hasEndTime) {
            $endTime = trim((string) $body['endTime']);
            if ($endTime !== '' && !$this->assertValidTime($endTime)) {
                return ResponseFactory::json(['error' => 'invalid_time'], 400, $response);
            }
            $data['endTime'] = $endTime;
        }

        if (isset($body['visibility'])) {
            $visibility = (int) $body['visibility'];
            if ($visibility < 0 || $visibility > 2) {
                return ResponseFactory::json(['error' => 'invalid_visibility'], 400, $response);
            }
            $data['visibility'] = $visibility;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        try {
            $event = $this->calendarService->update($args['id'], $user->id, $data);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->calendarService->delete($args['id'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->calendarService->get($args['id'], $user->id);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $start = !empty($params['start']) ? $params['start'] : null;
        $end = !empty($params['end']) ? $params['end'] : null;
        $range = !empty($params['range']) ? $params['range'] : null;

        if ($start !== null && !$this->assertValidDate($start)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }
        if ($end !== null && !$this->assertValidDate($end)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }

        if ($start === null && $end === null && $range !== null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($range === 'week') {
                $dayOfWeek = (int) $now->format('N');
                $monday = $now->modify('-' . ($dayOfWeek - 1) . ' days');
                $sunday = $monday->modify('+6 days');
                $start = $monday->format('Y-m-d');
                $end = $sunday->format('Y-m-d');
            } elseif ($range === 'month') {
                $firstDay = $now->modify('first day of this month');
                $lastDay = $now->modify('last day of this month');
                $start = $firstDay->format('Y-m-d');
                $end = $lastDay->format('Y-m-d');
            }
        }

        $result = $this->calendarService->listVisible($user->id, $start, $end, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function all(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $start = !empty($params['start']) ? $params['start'] : null;
        $end = !empty($params['end']) ? $params['end'] : null;

        if ($start !== null && !$this->assertValidDate($start)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }
        if ($end !== null && !$this->assertValidDate($end)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }

        if ($start === null && $end === null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $start = $now->modify('first day of this month')->format('Y-m-d');
            $end = $now->modify('last day of this month')->format('Y-m-d');
        } elseif ($start === null || $end === null) {
            return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
        }

        $types = CalendarFeedService::SUPPORTED_TYPES;
        if (!empty($params['types'])) {
            if (!is_string($params['types'])) {
                return ResponseFactory::json(['error' => 'invalid_type'], 400, $response);
            }
            $types = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $params['types'])),
                fn(string $type) => $type !== '',
            )));
            foreach ($types as $type) {
                if (!in_array($type, CalendarFeedService::SUPPORTED_TYPES, true)) {
                    return ResponseFactory::json(['error' => 'invalid_type'], 400, $response);
                }
            }
        }

        try {
            $feed = $this->calendarFeedService->buildFeed($user->id, $start, $end, $types);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }

        return ResponseFactory::json($feed, 200, $response);
    }

    public function addParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $participantId = trim((string) ($body['userId'] ?? ''));
        if ($participantId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        try {
            $result = $this->calendarService->addParticipant($args['id'], $user->id, $participantId);
            return ResponseFactory::json(['data' => $result], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->calendarService->removeParticipant($args['id'], $user->id, $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function errorResponse(\RuntimeException $e, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$e->getMessage()] ?? ['internal_error', 500];
        return ResponseFactory::json(['error' => $mapped[0]], $mapped[1], $response);
    }
}
