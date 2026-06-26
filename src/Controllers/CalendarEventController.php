<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\CalendarEventService;

final readonly class CalendarEventController
{
    private const array ERROR_MAP = [
        'Event not found' => ['event_not_found', 404],
        'Forbidden' => ['forbidden', 403],
        'Invalid datetime' => ['invalid_datetime', 400],
    ];

    public function __construct(
        private CalendarEventService $calendarService,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $title = trim((string) ($body['title'] ?? ''));
        $startTime = trim((string) ($body['startTime'] ?? ''));
        $endTime = trim((string) ($body['endTime'] ?? ''));
        $visibility = isset($body['visibility']) ? (int) $body['visibility'] : 0;
        $participants = isset($body['participants']) && is_array($body['participants'])
            ? $body['participants']
            : [];

        if ($title === '') {
            return ResponseFactory::json(['error' => 'title_required'], 400, $response);
        }
        if ($startTime === '' || $endTime === '') {
            return ResponseFactory::json(['error' => 'time_required'], 400, $response);
        }
        if ($visibility < 0 || $visibility > 2) {
            return ResponseFactory::json(['error' => 'invalid_visibility'], 400, $response);
        }
        if ($startTime >= $endTime) {
            return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
        }

        try {
            $event = $this->calendarService->create($user->id, [
                'title' => $title,
                'description' => !empty($body['description']) ? trim((string) $body['description']) : null,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'visibility' => $visibility,
                'participants' => $participants,
            ]);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e, $response);
        }

        return ResponseFactory::json(['data' => $event], 201, $response);
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

        if (isset($body['startTime'])) {
            $data['startTime'] = trim((string) $body['startTime']);
        }

        if (isset($body['endTime'])) {
            $data['endTime'] = trim((string) $body['endTime']);
        }

        if (isset($body['visibility'])) {
            $visibility = (int) $body['visibility'];
            if ($visibility < 0 || $visibility > 2) {
                return ResponseFactory::json(['error' => 'invalid_visibility'], 400, $response);
            }
            $data['visibility'] = $visibility;
        }

        if (isset($data['startTime']) && isset($data['endTime']) && $data['startTime'] >= $data['endTime']) {
            return ResponseFactory::json(['error' => 'invalid_time_range'], 400, $response);
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

        if ($start === null && $end === null && $range !== null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($range === 'week') {
                $dayOfWeek = (int) $now->format('N');
                $monday = $now->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
                $sunday = $monday->modify('+6 days')->setTime(23, 59, 59);
                $start = $monday->format('Y-m-d\TH:i:s\Z');
                $end = $sunday->format('Y-m-d\TH:i:s\Z');
            } elseif ($range === 'month') {
                $firstDay = $now->modify('first day of this month')->setTime(0, 0, 0);
                $lastDay = $now->modify('last day of this month')->setTime(23, 59, 59);
                $start = $firstDay->format('Y-m-d\TH:i:s\Z');
                $end = $lastDay->format('Y-m-d\TH:i:s\Z');
            }
        }

        $result = $this->calendarService->listVisible($user->id, $start, $end, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
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
