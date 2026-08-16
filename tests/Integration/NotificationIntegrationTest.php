<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sinclear\Api\Controllers\NotificationController;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\NotificationService;

class NotificationIntegrationTest extends TestCase
{
    private const FORUM_REPLY_DATA = [
        ['relation' => 'reply_author', 'object' => 'User', 'identifier' => 'user-reply'],
        ['relation' => 'comment_author', 'object' => 'User', 'identifier' => 'user-comment'],
        ['relation' => 'post_author', 'object' => 'User', 'identifier' => 'user-post'],
        ['relation' => 'parent_comment', 'object' => 'ForumPostComment', 'identifier' => 'comment-1'],
        ['relation' => 'parent_post', 'object' => 'ForumPost', 'identifier' => 'post-1'],
        ['relation' => 'parent_forum', 'object' => 'Forum', 'identifier' => 'forum-1'],
    ];
    private PDO $db;
    private NotificationController $controller;
    private NotificationService $service;
    private NotificationRepository $notificationRepo;
    private PushSubscriptionRepository $pushSubRepo;

    protected function setUp(): void
    {
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['DB_HOST'] ?? '127.0.0.1',
                $_ENV['DB_PORT'] ?? '3306',
                $_ENV['DB_NAME'] ?? 'sinclear_test',
            ),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $this->db->exec("SET time_zone = '+00:00'");

        $this->db->exec("DROP TABLE IF EXISTS Notification");
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS NotificationPreference");
        $this->db->exec("DROP TABLE IF EXISTS User");

        $this->db->exec("
            CREATE TABLE User (
                id varchar(191) NOT NULL PRIMARY KEY,
                email varchar(191) NOT NULL UNIQUE,
                passwordHash varchar(191) NOT NULL,
                displayName varchar(191) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                birthday datetime(3) DEFAULT NULL,
                isAdmin tinyint NOT NULL DEFAULT 0,
                discordId varchar(191) DEFAULT NULL,
                image text DEFAULT NULL,
                discordAvatarHash varchar(255) DEFAULT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE Notification (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                type varchar(64) NOT NULL,
                title varchar(255) NOT NULL,
                body text NOT NULL,
                data json DEFAULT NULL,
                isRead tinyint(1) NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                KEY idx_notification_user_read_created (userId, isRead, createdAt),
                CONSTRAINT fk_notification_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE PushSubscription (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                type varchar(20) NOT NULL,
                endpoint text NOT NULL,
                p256dh text DEFAULT NULL,
                auth text DEFAULT NULL,
                userAgent varchar(255) DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                UNIQUE KEY idx_pushsub_endpoint (endpoint(255)),
                KEY idx_pushsub_user (userId)
            )
        ");

        $this->db->exec("
            CREATE TABLE NotificationPreference (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                type varchar(64) NOT NULL,
                state varchar(16) NOT NULL DEFAULT 'enabled',
                data json DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                UNIQUE KEY idx_notifpref_user_type (userId, type)
            )
        ");

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-1', 'a@test.com', 'hash', 'Alice', NOW(3))");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', NOW(3))");

        $this->notificationRepo = new NotificationRepository($this->db);
        $this->pushSubRepo = new PushSubscriptionRepository($this->db);
        $prefRepo = new \Sinclear\Api\Repository\NotificationPreferenceRepository($this->db);
        $prefService = new \Sinclear\Api\Services\NotificationPreferenceService($prefRepo);
        $this->service = new NotificationService(
            notificationRepo: $this->notificationRepo,
            pushSubRepo: $this->pushSubRepo,
            preferenceService: $prefService,
        );
        $this->controller = new NotificationController(
            notificationService: $this->service,
            pushSubscriptionRepo: $this->pushSubRepo,
            preferenceService: $prefService,
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS Notification");
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS NotificationPreference");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function requestWithUser(string $method, string $path, ?array $body = null, ?string $userId = 'user-1'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($userId !== null) {
            $user = new AuthenticatedUser(id: $userId, email: 'test@test.com', isAdmin: false, jti: 'test-jti');
            $request = $request->withAttribute(AuthenticatedUser::class, $user);
        }
        return $request;
    }

    // ── GET /notifications ────────────────────────────────

    public function testIndexReturnsNotifications(): void
    {
        $this->service->create('user-1', 'forum_reply', 'Title', 'Body', self::FORUM_REPLY_DATA);
        $this->service->create('user-1', 'forum_reply', 'Title 2', 'Body 2', self::FORUM_REPLY_DATA);

        $request = $this->requestWithUser('GET', '/notifications');
        $response = $this->controller->index($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $data['notifications']);
    }

    public function testIndexFiltersByUser(): void
    {
        $this->service->create('user-1', 'forum_reply', 'Title 1', 'Body 1', self::FORUM_REPLY_DATA);
        $this->service->create('user-2', 'forum_reply', 'Title 2', 'Body 2', self::FORUM_REPLY_DATA);

        $request = $this->requestWithUser('GET', '/notifications', userId: 'user-1');
        $response = $this->controller->index($request, new Response());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $data['notifications']);
        $this->assertSame('user-1', $data['notifications'][0]['userId']);
    }

    public function testIndexWithSinceParameter(): void
    {
        $this->service->create('user-1', 'forum_reply', 'Old', 'Body', self::FORUM_REPLY_DATA);

        $request = $this->requestWithUser('GET', '/notifications', userId: 'user-1');
        $request = $request->withQueryParams(['since' => '9999-01-01 00:00:00']);
        $response = $this->controller->index($request, new Response());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertEmpty($data['notifications']);
    }

    public function testIndexExcludesReadNotifications(): void
    {
        $id = $this->service->create('user-1', 'forum_reply', 'Title', 'Body', self::FORUM_REPLY_DATA);
        $this->service->markRead('user-1', [$id]);

        $request = $this->requestWithUser('GET', '/notifications');
        $response = $this->controller->index($request, new Response());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertEmpty($data['notifications']);
    }

    // ── POST /notifications/read ──────────────────────────

    public function testMarkReadSuccess(): void
    {
        $id = $this->service->create('user-1', 'forum_reply', 'Title', 'Body', self::FORUM_REPLY_DATA);

        $request = $this->requestWithUser('POST', '/notifications/read', ['ids' => [$id]]);
        $response = $this->controller->markRead($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['ok']);

        $unread = $this->service->getUnread('user-1');
        $this->assertEmpty($unread);
    }

    public function testMarkReadEmptyBodyReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/read', []);
        $response = $this->controller->markRead($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMarkReadEmptyIdsReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/read', ['ids' => []]);
        $response = $this->controller->markRead($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMarkReadOnlyAffectsOwnNotifications(): void
    {
        $id1 = $this->service->create('user-1', 'forum_reply', 'Title 1', 'Body 1', self::FORUM_REPLY_DATA);
        $id2 = $this->service->create('user-2', 'forum_reply', 'Title 2', 'Body 2', self::FORUM_REPLY_DATA);

        $request = $this->requestWithUser('POST', '/notifications/read', ['ids' => [$id1, $id2]], userId: 'user-1');
        $response = $this->controller->markRead($request, new Response());

        $this->assertSame(200, $response->getStatusCode());

        $user2Unread = $this->service->getUnread('user-2');
        $this->assertCount(1, $user2Unread);
    }

    // ── POST /notifications/push-subscription ─────────────

    public function testSavePushSubscriptionWebpush(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://push.example.com/endpoint',
            'type' => 'webpush',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
        ]);

        $response = $this->controller->savePushSubscription($request, new Response());

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['ok']);

        $subs = $this->pushSubRepo->findByUserId('user-1', 'webpush');
        $this->assertCount(1, $subs);
        $this->assertSame('https://push.example.com/endpoint', $subs[0]['endpoint']);
    }

    public function testSavePushSubscriptionUnifiedpush(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://up.example.com/endpoint',
            'type' => 'unifiedpush',
        ]);

        $response = $this->controller->savePushSubscription($request, new Response());

        $this->assertSame(201, $response->getStatusCode());

        $subs = $this->pushSubRepo->findByUserId('user-1', 'unifiedpush');
        $this->assertCount(1, $subs);
    }

    public function testSavePushSubscriptionMissingEndpointReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'type' => 'webpush',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ]);

        $response = $this->controller->savePushSubscription($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSavePushSubscriptionInvalidTypeReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://example.com',
            'type' => 'unsupported-push-provider',
        ]);

        $response = $this->controller->savePushSubscription($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSavePushSubscriptionWebpushMissingKeysReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://example.com',
            'type' => 'webpush',
        ]);

        $response = $this->controller->savePushSubscription($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSavePushSubscriptionUpsertsOnDuplicateEndpoint(): void
    {
        $request1 = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://push.example.com/endpoint',
            'type' => 'webpush',
            'keys' => ['p256dh' => 'key1', 'auth' => 'auth1'],
        ]);
        $this->controller->savePushSubscription($request1, new Response());

        $request2 = $this->requestWithUser('POST', '/notifications/push-subscription', [
            'endpoint' => 'https://push.example.com/endpoint',
            'type' => 'webpush',
            'keys' => ['p256dh' => 'key2', 'auth' => 'auth2'],
        ]);
        $this->controller->savePushSubscription($request2, new Response());

        $subs = $this->pushSubRepo->findByUserId('user-1', 'webpush');
        $this->assertCount(1, $subs);
    }

    // ── DELETE /notifications/push-subscription ───────────

    public function testDeletePushSubscription(): void
    {
        $this->pushSubRepo->upsert([
            'userId' => 'user-1',
            'type' => 'webpush',
            'endpoint' => 'https://push.example.com/endpoint',
        ]);

        $request = $this->requestWithUser('DELETE', '/notifications/push-subscription', [
            'endpoint' => 'https://push.example.com/endpoint',
        ]);
        $response = $this->controller->deletePushSubscription($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $subs = $this->pushSubRepo->findByUserId('user-1');
        $this->assertEmpty($subs);
    }

    public function testDeletePushSubscriptionOnlyOwn(): void
    {
        $this->pushSubRepo->upsert([
            'userId' => 'user-1',
            'type' => 'webpush',
            'endpoint' => 'https://push.example.com/shared',
        ]);

        $request = $this->requestWithUser('DELETE', '/notifications/push-subscription', [
            'endpoint' => 'https://push.example.com/shared',
        ], userId: 'user-2');
        $response = $this->controller->deletePushSubscription($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $subs = $this->pushSubRepo->findByUserId('user-1');
        $this->assertCount(1, $subs);
    }

    public function testDeletePushSubscriptionMissingEndpointReturns400(): void
    {
        $request = $this->requestWithUser('DELETE', '/notifications/push-subscription', []);
        $response = $this->controller->deletePushSubscription($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    // ── GET /notifications/vapid-public-key ───────────────

    public function testVapidPublicKeyReturnsKey(): void
    {
        $_ENV['VAPID_PUBLIC_KEY'] = 'test-vapid-public-key';

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/notifications/vapid-public-key');
        $response = $this->controller->vapidPublicKey($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('test-vapid-public-key', $data['key']);

        unset($_ENV['VAPID_PUBLIC_KEY']);
    }

    public function testVapidPublicKeyReturnsEmptyWhenNotSet(): void
    {
        unset($_ENV['VAPID_PUBLIC_KEY']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/notifications/vapid-public-key');
        $response = $this->controller->vapidPublicKey($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('', $data['key']);
    }
}
