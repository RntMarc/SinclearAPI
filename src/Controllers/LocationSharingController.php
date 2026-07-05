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

        if (empty($body['recipient_ids']) || !is_array($body['recipient_ids'])) {
            return ResponseFactory::json(['error' => 'recipient_ids_required'], 400, $response);
        }
        if (empty($body['duration_seconds']) || !is_int($body['duration_seconds'])) {
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

        if (count($body['recipient_ids']) > 50) {
            return ResponseFactory::json(['error' => 'too_many_recipients'], 400, $response);
        }

        foreach ($body['recipient_ids'] as $rid) {
            if (!is_string($rid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rid)) {
                return ResponseFactory::json(['error' => 'invalid_recipient_id'], 400, $response);
            }
        }

        $session = $this->service->createSession($body, $user->id);
        return ResponseFactory::json(['data' => $session], 201, $response);
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

        if (array_key_exists('duration_seconds', $body)) {
            $d = $body['duration_seconds'];
            if (!is_int($d) || $d < 300 || $d > 86400) {
                return ResponseFactory::json(['error' => 'duration_seconds_out_of_range'], 400, $response);
            }
        }
        if (array_key_exists('is_active', $body)) {
            if (!is_bool($body['is_active'])) {
                return ResponseFactory::json(['error' => 'is_active_must_be_boolean'], 400, $response);
            }
        }

        try {
            $updated = $this->service->updateSession($id, $body);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 404, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $existing = $this->service->getSession($id);
        if ($existing === null) {
            return ResponseFactory::noContent($response);
        }

        if (!$this->policy->canModify($user, $existing['ownerId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->service->updateSession($id, ['is_active' => false]);
        return ResponseFactory::noContent($response);
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

        if (!$existing['isActive']) {
            return ResponseFactory::json(['error' => 'session_inactive'], 400, $response);
        }

        if (!isset($body['latitude']) || !is_numeric($body['latitude'])) {
            return ResponseFactory::json(['error' => 'latitude_required'], 400, $response);
        }
        if (!isset($body['longitude']) || !is_numeric($body['longitude'])) {
            return ResponseFactory::json(['error' => 'longitude_required'], 400, $response);
        }

        $lat = (float) $body['latitude'];
        $lng = (float) $body['longitude'];
        if ($lat < -90 || $lat > 90) {
            return ResponseFactory::json(['error' => 'latitude_out_of_range'], 400, $response);
        }
        if ($lng < -180 || $lng > 180) {
            return ResponseFactory::json(['error' => 'longitude_out_of_range'], 400, $response);
        }

        if (isset($body['accuracy']) && (is_numeric($body['accuracy']) && $body['accuracy'] < 0)) {
            return ResponseFactory::json(['error' => 'accuracy_must_be_positive'], 400, $response);
        }

        if (empty($body['recordedAt']) || !is_string($body['recordedAt'])) {
            return ResponseFactory::json(['error' => 'recordedAt_required'], 400, $response);
        }

        $recordedAt = $body['recordedAt'];
        $recordedDt = \DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $recordedAt)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $recordedAt)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:s.v', $recordedAt)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:s.u', $recordedAt)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:s', $recordedAt);

        if ($recordedDt === false) {
            return ResponseFactory::json(['error' => 'recordedAt_invalid_format'], 400, $response);
        }

        $recordedDt->setTimezone(new \DateTimeZone('UTC'));

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
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
                'recordedAt' => $recordedDt->format('Y-m-d\TH:i:s.u'),
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
