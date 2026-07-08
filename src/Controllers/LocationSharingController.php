<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\LocationSharingPolicy;
use Sinclear\Api\Services\LocationSharingService;

final readonly class LocationSharingController
{
    public function __construct(
        private LocationSharingService $service,
        private LocationSharingPolicy $policy,
    ) {}

    public function listSessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $sessions = $this->service->listMySessions($user->id);
        return ResponseFactory::json(['data' => $sessions], 200, $response);
    }

    public function listActive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $active = $this->service->listContactSessions($user->id);
        return ResponseFactory::json(['data' => $active], 200, $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $session = $this->service->getSessionDetail($id);
        if ($session === null) {
            return ResponseFactory::json(['error' => 'session_not_found'], 404, $response);
        }

        $isRecipient = $this->service->isRecipient($id, $user->id);
        if (!$this->policy->canView($user, $session['ownerId'], $isRecipient)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        return ResponseFactory::json(['data' => $session], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['recipient_ids']) || !is_array($body['recipient_ids'])) {
            return ResponseFactory::json(['error' => 'recipient_ids_required'], 400, $response);
        }

        $recipientIds = $body['recipient_ids'];
        if (count($recipientIds) < 1 || count($recipientIds) > 50) {
            return ResponseFactory::json(['error' => 'recipient_ids_count_invalid'], 400, $response);
        }

        foreach ($recipientIds as $rid) {
            if (!is_string($rid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rid)) {
                return ResponseFactory::json(['error' => 'recipient_id_invalid'], 400, $response);
            }
        }

        if (!isset($body['duration_seconds']) || !is_int($body['duration_seconds'])) {
            return ResponseFactory::json(['error' => 'duration_seconds_required'], 400, $response);
        }

        $duration = $body['duration_seconds'];
        if ($duration < 300 || $duration > 86400) {
            return ResponseFactory::json(['error' => 'duration_seconds_out_of_range'], 400, $response);
        }

        $frequency = $body['frequency_seconds'] ?? 600;
        if (!is_int($frequency) || $frequency < 300 || $frequency > 1200) {
            return ResponseFactory::json(['error' => 'frequency_seconds_out_of_range'], 400, $response);
        }

        $sharingMode = $body['sharing_mode'] ?? 'location';
        if (!in_array($sharingMode, ['location', 'route'], true)) {
            return ResponseFactory::json(['error' => 'sharing_mode_invalid'], 400, $response);
        }

        try {
            $session = $this->service->createSession([
                'recipient_ids' => $recipientIds,
                'sharing_mode' => $sharingMode,
                'duration_seconds' => $duration,
                'frequency_seconds' => $frequency,
            ], $user->id);
            return ResponseFactory::json(['data' => $session], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $existing = $this->service->getSession($id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'session_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $existing['ownerId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        if (isset($body['duration_seconds'])) {
            if (!is_int($body['duration_seconds']) || $body['duration_seconds'] < 300 || $body['duration_seconds'] > 86400) {
                return ResponseFactory::json(['error' => 'duration_seconds_out_of_range'], 400, $response);
            }
        }

        if (isset($body['is_active']) && !is_bool($body['is_active'])) {
            return ResponseFactory::json(['error' => 'is_active_invalid'], 400, $response);
        }

        try {
            $session = $this->service->updateSession($id, $body);
            return ResponseFactory::json(['data' => $session], 200, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $existing = $this->service->getSession($id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'session_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $existing['ownerId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->service->deleteSession($id);
        return ResponseFactory::json(['data' => ['id' => $id]], 200, $response);
    }

    public function createLocation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $existing = $this->service->getSession($id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'session_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $existing['ownerId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        if (!isset($body['latitude']) || !is_numeric($body['latitude'])) {
            return ResponseFactory::json(['error' => 'latitude_required'], 400, $response);
        }
        $lat = (float) $body['latitude'];
        if ($lat < -90 || $lat > 90) {
            return ResponseFactory::json(['error' => 'latitude_out_of_range'], 400, $response);
        }

        if (!isset($body['longitude']) || !is_numeric($body['longitude'])) {
            return ResponseFactory::json(['error' => 'longitude_required'], 400, $response);
        }
        $lng = (float) $body['longitude'];
        if ($lng < -180 || $lng > 180) {
            return ResponseFactory::json(['error' => 'longitude_out_of_range'], 400, $response);
        }

        if (array_key_exists('accuracy', $body)) {
            if (!is_numeric($body['accuracy']) || $body['accuracy'] < 0) {
                return ResponseFactory::json(['error' => 'accuracy_invalid'], 400, $response);
            }
        }

        if (empty($body['recordedAt']) || !is_string($body['recordedAt'])) {
            return ResponseFactory::json(['error' => 'recordedAt_required'], 400, $response);
        }

        $recordedAt = $body['recordedAt'];
        $utc = new \DateTimeZone('UTC');
        $recordedDt = \DateTime::createFromFormat('Y-m-d H:i:s', $recordedAt, $utc);

        if ($recordedDt === false) {
            return ResponseFactory::json(['error' => 'recordedAt_invalid_format'], 400, $response);
        }

        $now = new \DateTime('now', $utc);
        if ($recordedDt > $now) {
            return ResponseFactory::json(['error' => 'recordedAt_cannot_be_future'], 400, $response);
        }

        $maxPast = clone $now;
        $maxPast->modify('-5 minutes');
        if ($recordedDt < $maxPast) {
            return ResponseFactory::json(['error' => 'recordedAt_too_old'], 400, $response);
        }

        try {
            $locationId = $this->service->addLocation($id, [
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $body['accuracy'] ?? null,
                'recordedAt' => $recordedDt->format('Y-m-d H:i:s'),
            ]);
            return ResponseFactory::json(['data' => ['id' => $locationId]], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function listLocations(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $existing = $this->service->getSession($id);
        if ($existing === null) {
            return ResponseFactory::json(['error' => 'session_not_found'], 404, $response);
        }

        $isRecipient = $this->service->isRecipient($id, $user->id);
        if (!$this->policy->canView($user, $existing['ownerId'], $isRecipient)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $params = $request->getQueryParams();
        $since = $params['since'] ?? null;

        $locations = $this->service->listLocations($id, $since);
        return ResponseFactory::json(['data' => $locations], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
