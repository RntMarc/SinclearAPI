<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\NotificationRepository;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepo,
        private PushService $pushService,
    ) {}

    public function createNotification(string $userId, string $type, string $entityId): string
    {
        $id = $this->notificationRepo->create($userId, $type, $entityId);

        $this->pushService->sendNotificationToUser($userId, $id);

        return $id;
    }

    public function getNotification(string $userId, string $notificationId): ?array
    {
        return $this->notificationRepo->findById($notificationId, $userId);
    }

    public function listNotifications(string $userId, ?string $since, int $limit): array
    {
        $limit = max(1, min(100, $limit));

        $notifications = $this->notificationRepo->listByUser($userId, $since, $limit);

        return array_map(fn(array $n) => $this->formatNotification($n), $notifications);
    }

    public function markAsRead(string $userId, string $notificationId): bool
    {
        return $this->notificationRepo->delete($notificationId, $userId);
    }

    public function markAllAsRead(string $userId): int
    {
        return $this->notificationRepo->deleteAllByUser($userId);
    }

    public function countUnread(string $userId): int
    {
        return $this->notificationRepo->countByUser($userId);
    }

    private function formatNotification(array $notification): array
    {
        return [
            'id' => $notification['id'],
            'type' => $notification['type'],
            'entityId' => $notification['entityId'],
            'createdAt' => $notification['createdAt'],
        ];
    }
}
