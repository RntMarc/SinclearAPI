<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Services\NotificationService;

class NotificationServiceTest extends TestCase
{
    private PDO $db;
    private NotificationService $service;

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

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-1', 'a@test.com', 'hash', 'Alice', NOW(3))");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', NOW(3))");

        $repo = new NotificationRepository($this->db);
        $pushSubRepo = new PushSubscriptionRepository($this->db);
        $this->service = new NotificationService(notificationRepo: $repo, pushSubRepo: $pushSubRepo);
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS Notification");
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ── create() ──────────────────────────────────────────

    public function testCreateReturnsUuid(): void
    {
        $id = $this->service->create(
            userId: 'user-1',
            type: 'forum_reply',
            title: 'Neue Antwort',
            body: 'Jemand hat geantwortet.',
            data: ['route' => '/forum/42'],
        );

        $this->assertNotEmpty($id);
        $this->assertIsString($id);

        $stmt = $this->db->prepare('SELECT * FROM Notification WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertNotEmpty($row);
        $this->assertSame('user-1', $row['userId']);
        $this->assertSame('forum_reply', $row['type']);
        $this->assertSame('Neue Antwort', $row['title']);
        $this->assertSame('Jemand hat geantwortet.', $row['body']);
        $this->assertStringContainsString('/forum/42', $row['data']);
        $this->assertSame(0, (int) $row['isRead']);
    }

    public function testCreateWithNullData(): void
    {
        $id = $this->service->create(
            userId: 'user-1',
            type: 'event_reminder',
            title: 'Erinnerung',
            body: 'Event startet gleich.',
        );

        $stmt = $this->db->prepare('SELECT data FROM Notification WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertNull($row['data']);
    }

    public function testCreateThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('user-1', '', 'Title', 'Body');
    }

    public function testCreateThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('user-1', 'forum_reply', '', 'Body');
    }

    public function testCreateThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('user-1', 'forum_reply', 'Title', '');
    }

    public function testCreateTrimsWhitespace(): void
    {
        $id = $this->service->create(
            userId: 'user-1',
            type: '  forum_reply  ',
            title: '  Neue Antwort  ',
            body: '  Body  ',
        );

        $stmt = $this->db->prepare('SELECT type, title, body FROM Notification WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame('forum_reply', $row['type']);
        $this->assertSame('Neue Antwort', $row['title']);
        $this->assertSame('Body', $row['body']);
    }

    // ── getUnread() ───────────────────────────────────────

    public function testGetUnreadReturnsOnlyUnreadForUser(): void
    {
        $this->service->create('user-1', 'type1', 'Title 1', 'Body 1');
        $this->service->create('user-1', 'type2', 'Title 2', 'Body 2');
        $this->service->create('user-2', 'type1', 'Title 3', 'Body 3');

        $result = $this->service->getUnread('user-1');

        $this->assertCount(2, $result);
        foreach ($result as $n) {
            $this->assertSame('user-1', $n['userId']);
            $this->assertFalse($n['isRead']);
        }
    }

    public function testGetUnreadExcludesReadNotifications(): void
    {
        $id = $this->service->create('user-1', 'type1', 'Title', 'Body');
        $this->service->markRead('user-1', [$id]);

        $result = $this->service->getUnread('user-1');

        $this->assertCount(0, $result);
    }

    public function testGetUnreadWithSinceFiltersCorrectly(): void
    {
        $this->service->create('user-1', 'type1', 'Old', 'Body');
        $this->service->create('user-1', 'type2', 'New', 'Body');

        $result = $this->service->getUnread('user-1', since: '9999-01-01 00:00:00');

        $this->assertEmpty($result);
    }

    public function testGetUnreadWithSinceReturnsRecentItems(): void
    {
        $this->service->create('user-1', 'type1', 'Title', 'Body');

        $result = $this->service->getUnread('user-1', since: '2000-01-01 00:00:00');

        $this->assertCount(1, $result);
    }

    public function testGetUnreadReturnsEmptyForNoNotifications(): void
    {
        $result = $this->service->getUnread('user-1');

        $this->assertEmpty($result);
    }

    public function testGetUnreadDecodesDataField(): void
    {
        $id = $this->service->create('user-1', 'type1', 'Title', 'Body', ['route' => '/forum/1']);
        $result = $this->service->getUnread('user-1');

        $this->assertIsArray($result[0]['data']);
        $this->assertSame('/forum/1', $result[0]['data']['route']);
    }

    // ── markRead() ────────────────────────────────────────

    public function testMarkReadSetsIsReadForUserIds(): void
    {
        $id1 = $this->service->create('user-1', 'type1', 'Title 1', 'Body 1');
        $id2 = $this->service->create('user-1', 'type2', 'Title 2', 'Body 2');

        $this->service->markRead('user-1', [$id1, $id2]);

        $result = $this->service->getUnread('user-1');
        $this->assertEmpty($result);
    }

    public function testMarkReadOnlyAffectsOwnNotifications(): void
    {
        $id1 = $this->service->create('user-1', 'type1', 'Title 1', 'Body 1');
        $id2 = $this->service->create('user-2', 'type2', 'Title 2', 'Body 2');

        $this->service->markRead('user-1', [$id1, $id2]);

        $user2Unread = $this->service->getUnread('user-2');
        $this->assertCount(1, $user2Unread);
        $this->assertSame($id2, $user2Unread[0]['id']);
    }

    public function testMarkReadDoesNothingOnEmptyArray(): void
    {
        $id = $this->service->create('user-1', 'type1', 'Title', 'Body');

        $this->service->markRead('user-1', []);

        $result = $this->service->getUnread('user-1');
        $this->assertCount(1, $result);
    }
}
