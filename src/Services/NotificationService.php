<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepo,
        private PushSubscriptionRepository $pushSubRepo,
        private ?WebPush $webPush = null,
        private ?Client $httpClient = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function create(string $userId, string $type, string $title, string $body, ?array $data = null): string
    {
        $type = trim($type);
        $title = trim($title);
        $body = trim($body);

        if ($type === '' || $title === '' || $body === '') {
            throw new \InvalidArgumentException('type, title, and body are required');
        }

        $record = $this->notificationRepo->create([
            'userId' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $notification = [
            'id' => $record['id'],
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'createdAt' => $record['createdAt'],
        ];

        $this->sendWebPush($userId, $notification);
        $this->sendUnifiedPush($userId, $notification);

        return $record['id'];
    }

    /**
     * @return array[]
     */
    public function getUnread(string $userId, ?string $since = null): array
    {
        return $this->notificationRepo->getUnread($userId, $since);
    }

    public function markRead(string $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $cleaned = array_values(array_filter(array_map('trim', $ids), fn(string $id): bool => $id !== ''));
        if ($cleaned === []) {
            return;
        }

        $this->notificationRepo->markRead($userId, $cleaned);
    }

    private function sendWebPush(string $userId, array $notification): void
    {
        if ($this->webPush === null) {
            return;
        }

        $subscriptions = $this->pushSubRepo->findByUserId($userId, 'webpush');
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode($notification, JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            try {
                $webPushSubscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => [
                        'p256dh' => $sub['p256dh'],
                        'auth' => $sub['auth'],
                    ],
                ]);

                $report = $this->webPush->sendOneNotification($webPushSubscription, $payload);

                if ($report->isSubscriptionExpired()) {
                    $this->pushSubRepo->deleteById($sub['id']);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Web Push delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendUnifiedPush(string $userId, array $notification): void
    {
        if ($this->httpClient === null) {
            return;
        }

        $subscriptions = $this->pushSubRepo->findByUserId($userId, 'unifiedpush');
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode($notification, JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            try {
                $this->httpClient->post($sub['endpoint'], [
                    'body' => $payload,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 10,
                ]);
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode();

                if ($statusCode === 410) {
                    $this->pushSubRepo->deleteById($sub['id']);
                    continue;
                }

                $this->logger?->warning('UnifiedPush delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'status' => $statusCode,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $this->logger?->warning('UnifiedPush delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
