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

        if ($type === '') {
            throw new \InvalidArgumentException('type is required');
        }

        $data = $this->normalizeData($type, $data);

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
            'data' => $data,
            'createdAt' => $record['createdAt'],
        ];

        $this->sendWebPush($userId, $notification);
        $this->sendUnifiedPush($userId, $notification);

        return $record['id'];
    }


    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>|null
     */
    private function normalizeData(string $type, ?array $data): ?array
    {
        return match ($type) {
            'forum_reply' => $this->normalizeForumReplyData($data),
            default => throw new \InvalidArgumentException('unsupported notification type'),
        };
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeForumReplyData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('forum_reply data is required');
        }

        $requiredRelations = [
            'reply_author' => 'User',
            'comment_author' => 'User',
            'post_author' => 'User',
            'parent_comment' => 'ForumPostComment',
            'parent_post' => 'ForumPost',
            'parent_forum' => 'Forum',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('forum_reply data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('forum_reply data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('forum_reply data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('forum_reply data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
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

    /**
     * Proactively removes expired push subscriptions.
     *
     * Currently uses reactive cleanup (on 410/404 during delivery) as the
     * primary mechanism. This method exists for spec compliance and can be
     * extended when a `failedAt`/`lastError` column is added to
     * `PushSubscription` to track delivery failures without sending test
     * notifications.
     *
     * @return int Number of subscriptions removed
     */
    public function cleanExpiredSubscriptions(): int
    {
        // Reactive cleanup happens in sendWebPush()/sendUnifiedPush()
        // when a 410/404 is received. A proactive sweep would require
        // tracking failed deliveries (e.g. failedAt column), which is not
        // currently implemented. Return 0 to indicate no proactive action.
        return 0;
    }
}
