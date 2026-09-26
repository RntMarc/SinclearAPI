<?php

namespace Sinclear\Api\Controllers;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\CalendarFeedService;
use Sinclear\Api\Support\DateTimeValue;

final readonly class CalendarEventController
{
    private const array ERROR_MAP = [
        'Event not found' => ['event_not_found', 404],
        'Forbidden' => ['forbidden', 403],
        'Invalid datetime' => ['invalid_datetime', 400],
        'Invalid date' => ['invalid_date', 400],
        'Invalid timezone' => ['invalid_timezone', 400],
        'Invalid time range' => ['invalid_time_range', 400],
        'Authentication required' => ['unauthorized', 401],
    ];

    public function __construct(
        private CalendarEventService $calendarService,
        private CalendarFeedService $calendarFeedService,
        private LoggerInterface $logger,
        private Settings $settings,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $title = trim((string) ($body['title'] ?? ''));
        $description = !empty($body['description']) ? trim((string) $body['description']) : null;
        $allDay = !empty($body['allDay']) ? (bool) $body['allDay'] : false;
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

        try {
            $timezone = DateTimeValue::normalizeTimeZone(
                isset($body['timezone']) && is_string($body['timezone']) ? $body['timezone'] : null,
            );
        } catch (\InvalidArgumentException) {
            return ResponseFactory::json(['error' => 'invalid_timezone'], 400, $response);
        }

        $data = [
            'title' => $title,
            'description' => $description,
            'allDay' => $allDay ? 1 : 0,
            'timezone' => $timezone,
            'visibility' => $visibility,
            'participants' => $participants,
        ];

        if ($allDay) {
            $startDate = trim((string) ($body['startDate'] ?? ''));
            $endDate = trim((string) ($body['endDate'] ?? ''));
            if ($startDate === '' || $endDate === '') {
                return ResponseFactory::json(['error' => 'date_required'], 400, $response);
            }
            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            if ($startDate > $endDate) {
                return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
            }
            if ($this->hasNonEmpty($body, ['startAt', 'endAt'])) {
                return ResponseFactory::json(['error' => 'time_forbidden'], 400, $response);
            }

            $data['startDate'] = $startDate;
            $data['endDate'] = $endDate;
            $data['startAt'] = null;
            $data['endAt'] = null;
        } else {
            $startAt = trim((string) ($body['startAt'] ?? ''));
            $endAt = trim((string) ($body['endAt'] ?? ''));
            if ($startAt === '' || $endAt === '') {
                return ResponseFactory::json(['error' => 'time_required'], 400, $response);
            }
            $start = $this->parseInstantOrNull($startAt);
            $end = $this->parseInstantOrNull($endAt);
            if ($start === null || $end === null) {
                return ResponseFactory::json(['error' => 'invalid_datetime'], 400, $response);
            }
            if ($end <= $start) {
                return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
            }
            if ($this->hasNonEmpty($body, ['startDate', 'endDate'])) {
                return ResponseFactory::json(['error' => 'date_forbidden'], 400, $response);
            }

            $data['startAt'] = $startAt;
            $data['endAt'] = $endAt;
            $data['startDate'] = null;
            $data['endDate'] = null;
        }

        try {
            $event = $this->calendarService->create($user->id, $data);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $response);
        }

        return ResponseFactory::json(['data' => $event], 201, $response);
    }

    private function isValidDate(string $value): bool
    {
        try {
            DateTimeValue::parseDate($value);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function isValidInstant(string $value): bool
    {
        return $this->parseInstantOrNull($value) !== null;
    }

    private function parseInstantOrNull(string $value): ?DateTimeImmutable
    {
        try {
            return DateTimeValue::parseInstant($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param list<string> $fields */
    private function hasNonEmpty(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (isset($body[$field]) && trim((string) $body[$field]) !== '') {
                return true;
            }
        }

        return false;
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

        if (array_key_exists('allDay', $body)) {
            $data['allDay'] = (bool) $body['allDay'];
        }

        if (array_key_exists('timezone', $body)) {
            try {
                $data['timezone'] = DateTimeValue::normalizeTimeZone(
                    $body['timezone'] !== null ? (string) $body['timezone'] : null,
                );
            } catch (\InvalidArgumentException) {
                return ResponseFactory::json(['error' => 'invalid_timezone'], 400, $response);
            }
        }

        foreach (['startAt', 'endAt'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $raw = $body[$field];
            if ($raw === null || trim((string) $raw) === '') {
                $data[$field] = null;
                continue;
            }
            if (!$this->isValidInstant(trim((string) $raw))) {
                return ResponseFactory::json(['error' => 'invalid_datetime'], 400, $response);
            }
            $data[$field] = trim((string) $raw);
        }

        foreach (['startDate', 'endDate'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $raw = $body[$field];
            if ($raw === null || trim((string) $raw) === '') {
                $data[$field] = null;
                continue;
            }
            if (!$this->isValidDate(trim((string) $raw))) {
                return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
            }
            $data[$field] = trim((string) $raw);
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
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->calendarService->delete($args['id'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $event = $this->calendarService->get($args['id'], $user->id);
            return ResponseFactory::json(['data' => $event], 200, $response);
        } catch (\Throwable $e) {
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

        if ($start !== null && !$this->isValidDate($start)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }
        if ($end !== null && !$this->isValidDate($end)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }

        $rangeTimezone = $this->resolveRangeTimezone($params);
        if ($rangeTimezone === null) {
            return ResponseFactory::json(['error' => 'invalid_timezone'], 400, $response);
        }

        if ($start === null && $end === null && $range !== null) {
            $now = new DateTimeImmutable('now', DateTimeValue::assertTimeZone($rangeTimezone));

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

        try {
            $result = $this->calendarService->listVisible($user->id, $start, $end, $rangeTimezone, $page, $limit);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $response);
        }
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function all(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $start = !empty($params['start']) ? $params['start'] : null;
        $end = !empty($params['end']) ? $params['end'] : null;

        if ($start !== null && !$this->isValidDate($start)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }
        if ($end !== null && !$this->isValidDate($end)) {
            return ResponseFactory::json(['error' => 'invalid_date'], 400, $response);
        }

        $rangeTimezone = $this->resolveRangeTimezone($params);
        if ($rangeTimezone === null) {
            return ResponseFactory::json(['error' => 'invalid_timezone'], 400, $response);
        }

        if ($start === null && $end === null) {
            $now = new DateTimeImmutable('now', DateTimeValue::assertTimeZone($rangeTimezone));
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
            $feed = $this->calendarFeedService->buildFeed($user->id, $start, $end, $types, $rangeTimezone);
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $response);
        }
    }

    public function removeParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->calendarService->removeParticipant($args['id'], $user->id, $args['userId']);
            return ResponseFactory::noContent($response);
        } catch (\Throwable $e) {
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

    /**
     * Liest die Zeitzone fuer Zeitraumgrenzen aus den Query-Parametern.
     * Default ist UTC; liefert null bei ungueltigem Wert.
     */
    private function resolveRangeTimezone(array $params): ?string
    {
        $timezone = isset($params['timezone']) && is_string($params['timezone']) && trim($params['timezone']) !== ''
            ? trim($params['timezone'])
            : 'UTC';

        try {
            return DateTimeValue::assertTimeZone($timezone)->getName();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function errorResponse(\Throwable $e, ResponseInterface $response): ResponseInterface
    {
        $mapped = $e instanceof \PDOException
            ? self::mapDbError($e)
            : (self::ERROR_MAP[$e->getMessage()] ?? ['internal_error', 500]);
        [$code, $status] = $mapped;

        $context = [
            'exception' => $e,
            'errorCode' => $code,
            'status' => $status,
        ];
        if ($status >= 500) {
            $this->logger->error('Calendar request failed: ' . $e->getMessage(), $context);
        } else {
            $this->logger->warning('Calendar request rejected: ' . $e->getMessage(), $context);
        }

        $payload = ['error' => $code];
        if ($this->settings->app['debug']) {
            $payload['message'] = $e->getMessage();
        }
        return ResponseFactory::json($payload, $status, $response);
    }

    /**
     * Uebersetzt einen PDO-Fehler in einen passenden API-Fehlercode.
     * 1452 = Fremdschluessel verletzt (z.B. unbekannter Teilnehmer),
     * 1062 = Duplikat, 1264/1265/1292/1366 = Wert passt nicht ins Spaltenformat.
     */
    private static function mapDbError(\PDOException $e): array
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return match (true) {
            $driverCode === 1452 => ['invalid_reference', 400],
            $driverCode === 1062 => ['conflict', 409],
            in_array($driverCode, [1264, 1265, 1292, 1366], true) => ['invalid_value', 400],
            str_starts_with((string) $e->getCode(), '22') => ['invalid_value', 400],
            default => ['internal_error', 500],
        };
    }
}
