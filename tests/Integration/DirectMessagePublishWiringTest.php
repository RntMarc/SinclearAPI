<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\DirectMessageRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\Centrifugo\ChatEventPublisher;
use Sinclear\Api\Services\DirectMessageService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\RateLimiter;
use Sinclear\Api\Tests\Unit\FakeCentrifugoClient;

/**
 * DB-dependent integration test for DirectMessageService + Centrifugo wiring.
 *
 * Runs on server via update.sh (requires MySQL/MariaDB).
 * Skips automatically when DB is unavailable.
 */
class DirectMessagePublishWiringTest extends TestCase
{
    private PDO $db;
    private DirectMessageService $service;
    private FakeCentrifugoClient $fakeClient;

    protected function setUp(): void
    {
        try {
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
        } catch (\PDOException) {
            $this->markTestSkipped('Database not available');
        }

        // Drop tables in dependency order
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS NotificationPreference");
        $this->db->exec("DROP TABLE IF EXISTS Notification");
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS DirectMessage");
        $this->db->exec("DROP TABLE IF EXISTS ChatParticipant");
        $this->db->exec("DROP TABLE IF EXISTS ChatConversation");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

        // User table
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

        // Chat tables
        $this->db->exec("
            CREATE TABLE ChatConversation (
                id varchar(191) NOT NULL PRIMARY KEY,
                type enum('direct','group') NOT NULL DEFAULT 'direct',
                name varchar(255) DEFAULT NULL,
                image text DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
            )
        ");

        $this->db->exec("
            CREATE TABLE ChatParticipant (
                conversationId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                joinedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                lastReadSeq bigint unsigned NOT NULL DEFAULT 0,
                lastSeenAt datetime(3) DEFAULT NULL,
                PRIMARY KEY (conversationId, userId),
                KEY idx_participant_user (userId),
                CONSTRAINT fk_participant_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE,
                CONSTRAINT fk_participant_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE DirectMessage (
                id varchar(191) NOT NULL PRIMARY KEY,
                seq bigint unsigned NOT NULL AUTO_INCREMENT,
                conversationId varchar(191) NOT NULL,
                senderId varchar(191) NOT NULL,
                type enum('text','image','location') NOT NULL DEFAULT 'text',
                content text NOT NULL,
                payload json DEFAULT NULL,
                clientId varchar(64) DEFAULT NULL,
                editedAt datetime(3) DEFAULT NULL,
                deletedAt datetime(3) DEFAULT NULL,
                deletedBy varchar(191) DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_dm_seq (seq),
                KEY idx_dm_conversation_seq (conversationId, seq),
                KEY idx_dm_sender (senderId),
                CONSTRAINT fk_dm_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE,
                CONSTRAINT fk_dm_sender FOREIGN KEY (senderId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        // Notification tables (needed by NotificationService)
        $this->db->exec("
            CREATE TABLE Notification (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                type varchar(64) NOT NULL,
                dedupeKey varchar(191) DEFAULT NULL,
                title varchar(255) NOT NULL,
                body text NOT NULL,
                data json DEFAULT NULL,
                isRead tinyint(1) NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                KEY idx_notification_user_read_created (userId, isRead, createdAt),
                KEY idx_notification_dedupe (userId, dedupeKey, isRead),
                CONSTRAINT fk_notification_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE NotificationPreference (
                userId varchar(191) NOT NULL,
                type varchar(64) NOT NULL,
                state enum('enabled','disabled','custom') NOT NULL DEFAULT 'enabled',
                customData json DEFAULT NULL,
                PRIMARY KEY (userId, type)
            )
        ");

        $this->db->exec("
            CREATE TABLE PushSubscription (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                endpoint varchar(500) NOT NULL,
                p256dh varchar(255) NOT NULL,
                auth varchar(255) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_push_user (userId),
                CONSTRAINT fk_push_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        // Seed users
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('sender-1', 's1@test.de', 'x', 'Alice')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('recv-1', 'r1@test.de', 'x', 'Bob')");

        // Create conversation + participants
        $this->db->exec("INSERT INTO ChatConversation (id, type) VALUES ('test-conv', 'direct')");
        $this->db->exec("INSERT INTO ChatParticipant (conversationId, userId) VALUES ('test-conv', 'sender-1')");
        $this->db->exec("INSERT INTO ChatParticipant (conversationId, userId) VALUES ('test-conv', 'recv-1')");

        $conversationRepo = new ChatConversationRepository($this->db);
        $participantRepo = new ChatParticipantRepository($this->db);
        $messageRepo = new DirectMessageRepository($this->db);
        $userRepo = new UserRepository($this->db);
        $notificationService = new NotificationService(
            notificationRepo: new \Sinclear\Api\Repository\NotificationRepository($this->db),
            pushSubRepo: new \Sinclear\Api\Repository\PushSubscriptionRepository($this->db),
            preferenceService: new \Sinclear\Api\Services\NotificationPreferenceService(
                new \Sinclear\Api\Repository\NotificationPreferenceRepository($this->db)
            ),
            logger: new \Psr\Log\NullLogger(),
        );
        $rateLimiter = new RateLimiter();
        $this->fakeClient = new FakeCentrifugoClient();
        $eventPublisher = new ChatEventPublisher(client: $this->fakeClient, logger: new \Psr\Log\NullLogger());

        $this->service = new DirectMessageService(
            conversationRepo: $conversationRepo,
            participantRepo: $participantRepo,
            messageRepo: $messageRepo,
            userRepo: $userRepo,
            notificationService: $notificationService,
            rateLimiter: $rateLimiter,
            centrifugoClient: $this->fakeClient,
            eventPublisher: $eventPublisher,
        );
    }

    public function testSendMessagePublishesMessageCreatedEvent(): void
    {
        $result = $this->service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Hallo Welt!',
        ]);

        $this->assertSame('chat:test-conv', $this->fakeClient->publishedChannel);
        $this->assertSame('message_created', $this->fakeClient->publishedData['type']);
        $this->assertSame($result['id'], $this->fakeClient->publishedData['message']['id']);
        $this->assertSame('sender-1', $this->fakeClient->publishedData['message']['senderId']);
    }

    public function testSendMessagePersistsMessageInDb(): void
    {
        $result = $this->service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Persistente Nachricht',
        ]);

        // Verify persisted in DB
        $stmt = $this->db->prepare('SELECT * FROM DirectMessage WHERE id = ?');
        $stmt->execute([$result['id']]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('Persistente Nachricht', $row['content']);
        $this->assertSame('sender-1', $row['senderId']);
        $this->assertSame('test-conv', $row['conversationId']);
    }

    public function testSendMessageWithNoopClientStillPersists(): void
    {
        // Test with CENTRIFUGO_ENABLED=false (noop client)
        $noopClient = new \Sinclear\Api\Services\Centrifugo\CentrifugoClient(
            apiKey: '',
            apiUrl: '',
            timeout: 1,
            enabled: false,
            logger: new \Psr\Log\NullLogger(),
        );
        $noopPublisher = new ChatEventPublisher(client: $noopClient, logger: new \Psr\Log\NullLogger());

        $service = new DirectMessageService(
            conversationRepo: new ChatConversationRepository($this->db),
            participantRepo: new ChatParticipantRepository($this->db),
            messageRepo: new DirectMessageRepository($this->db),
            userRepo: new UserRepository($this->db),
            notificationService: new NotificationService(
                notificationRepo: new \Sinclear\Api\Repository\NotificationRepository($this->db),
                pushSubRepo: new \Sinclear\Api\Repository\PushSubscriptionRepository($this->db),
                preferenceService: new \Sinclear\Api\Services\NotificationPreferenceService(
                    new \Sinclear\Api\Repository\NotificationPreferenceRepository($this->db)
                ),
                logger: new \Psr\Log\NullLogger(),
            ),
            rateLimiter: new RateLimiter(),
            centrifugoClient: $noopClient,
            eventPublisher: $noopPublisher,
        );

        $result = $service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Noop Test',
        ]);

        // Message persisted despite noop client
        $this->assertNotNull($result['id']);
        $stmt = $this->db->prepare('SELECT content FROM DirectMessage WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame('Noop Test', $stmt->fetchColumn());
    }

    public function testSendMessageWithFailingClientStillPersists(): void
    {
        $this->fakeClient->publishException = new \RuntimeException('Centrifugo down');

        // Must not throw
        $result = $this->service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Trotzdem persistiert',
        ]);

        $this->assertNotNull($result['id']);
        $stmt = $this->db->prepare('SELECT content FROM DirectMessage WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame('Trotzdem persistiert', $stmt->fetchColumn());
    }

    public function testPresencePushSuppressionWhenOnline(): void
    {
        // Simulate receiver being online in Centrifugo channel
        $this->fakeClient->presenceResult = [
            'recv-1' => ['client' => 'abc', 'user' => 'recv-1'],
        ];

        $this->service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Push suppress test',
        ]);

        // Message published successfully
        $this->assertSame('message_created', $this->fakeClient->publishedData['type']);
    }

    public function testPresenceFallbackWhenCentrifugoFails(): void
    {
        // Simulate Centrifugo presence failure (fallback: push anyway)
        $this->fakeClient->presenceResult = [];

        $this->service->sendMessage('sender-1', 'test-conv', [
            'content' => 'Fallback test',
        ]);

        // Message still published
        $this->assertSame('message_created', $this->fakeClient->publishedData['type']);
    }
}
