<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\UserDeviceRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\NotificationPolicy;
use Sinclear\Api\Services\NotificationService;

final readonly class NotificationController
{
    public function __construct(
        private NotificationService $notificationService,
        private UserDeviceRepository $deviceRepo,
        private NotificationPolicy $policy,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();

        $since = !empty($params['since']) ? $params['since'] : null;
        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));

        $notifications = $this->notificationService->listNotifications($user->id, $since, $limit);
        $unreadCount = $this->notificationService->countUnread($user->id);

        return ResponseFactory::json([
            'data' => $notifications,
            'meta' => [
                'unreadCount' => $unreadCount,
            ],
        ], 200, $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $notification = $this->notificationService->getNotification($user->id, $args['id']);
        if ($notification === null) {
            return ResponseFactory::json(['error' => 'notification_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $notification], 200, $response);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $deleted = $this->notificationService->markAsRead($user->id, $args['id']);
        if (!$deleted) {
            return ResponseFactory::json(['error' => 'notification_not_found'], 404, $response);
        }

        return ResponseFactory::noContent($response);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $count = $this->notificationService->markAllAsRead($user->id);

        return ResponseFactory::json(['data' => ['deleted' => $count]], 200, $response);
    }

    public function listDevices(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $devices = $this->deviceRepo->findByUserId($user->id);

        return ResponseFactory::json(['data' => $devices], 200, $response);
    }

    public function registerDevice(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $token = trim((string) ($body['token'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $deviceName = !empty($body['deviceName']) ? trim((string) $body['deviceName']) : null;

        if ($token === '') {
            return ResponseFactory::json(['error' => 'token_required'], 400, $response);
        }

        $validPlatforms = ['android', 'web', 'ios', 'linux', 'windows'];
        if (!in_array($platform, $validPlatforms, true)) {
            return ResponseFactory::json(['error' => 'invalid_platform'], 400, $response);
        }

        $deviceId = $this->deviceRepo->create($user->id, $token, $platform, $deviceName);

        return ResponseFactory::json([
            'data' => [
                'id' => $deviceId,
                'platform' => $platform,
                'pushEnabled' => true,
            ],
        ], 201, $response);
    }

    public function unregisterDevice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $device = $this->deviceRepo->findById($args['deviceId'], $user->id);
        if ($device === null) {
            return ResponseFactory::json(['error' => 'device_not_found'], 404, $response);
        }

        $this->deviceRepo->delete($args['deviceId'], $user->id);

        return ResponseFactory::noContent($response);
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
