<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\NotificationPreferenceService;
use Sinclear\Api\Services\NotificationService;

final readonly class NotificationController
{
    public function __construct(
        private NotificationService $notificationService,
        private PushSubscriptionRepository $pushSubscriptionRepo,
        private NotificationPreferenceService $preferenceService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $since = $params['since'] ?? null;

        $notifications = $this->notificationService->getUnread($user->id, $since);

        return ResponseFactory::json(['notifications' => $notifications], 200, $response);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['ids']) || !is_array($body['ids']) || $body['ids'] === []) {
            return ResponseFactory::json(['error' => 'ids_required'], 400, $response);
        }

        $this->notificationService->markRead($user->id, $body['ids']);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function savePushSubscription(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $endpoint = $body['endpoint'] ?? null;
        $type = $body['type'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return ResponseFactory::json(['error' => 'endpoint_required'], 400, $response);
        }

        if (!in_array($type, ['webpush', 'unifiedpush'], true)) {
            return ResponseFactory::json(['error' => 'type_invalid'], 400, $response);
        }

        if ($type === 'webpush') {
            $keys = $body['keys'] ?? null;
            if (!is_array($keys) || empty($keys['p256dh']) || empty($keys['auth'])) {
                return ResponseFactory::json(['error' => 'webpush_keys_required'], 400, $response);
            }
        }

        $this->pushSubscriptionRepo->upsert([
            'userId' => $user->id,
            'type' => $type,
            'endpoint' => $endpoint,
            'p256dh' => $body['keys']['p256dh'] ?? null,
            'auth' => $body['keys']['auth'] ?? null,
            'userAgent' => $request->getHeaderLine('User-Agent') ?: null,
        ]);

        return ResponseFactory::json(['ok' => true], 201, $response);
    }

    public function deletePushSubscription(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $endpoint = $body['endpoint'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return ResponseFactory::json(['error' => 'endpoint_required'], 400, $response);
        }

        $this->pushSubscriptionRepo->deleteByUserAndEndpoint($user->id, $endpoint);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function vapidPublicKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $key = $_ENV['VAPID_PUBLIC_KEY'] ?? '';

        return ResponseFactory::json(['key' => $key], 200, $response);
    }

    public function getPreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        return ResponseFactory::json(['data' => $this->preferenceService->getAll($user->id)], 200, $response);
    }

    public function updatePreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['preferences']) || !is_array($body['preferences']) || $body['preferences'] === []) {
            return ResponseFactory::json(['error' => 'preferences_required'], 400, $response);
        }

        try {
            $prefs = $this->preferenceService->update($user->id, $body['preferences']);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['data' => $prefs], 200, $response);
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
