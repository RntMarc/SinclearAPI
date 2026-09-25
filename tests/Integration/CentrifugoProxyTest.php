<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinclear\Api\Controllers\CentrifugoProxyController;
use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\DirectMessageRepository;
use Sinclear\Api\Repository\MessageReactionRepository;
use Sinclear\Api\Repository\NotificationPreferenceRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\Centrifugo\ChatEventPublisher;
use Sinclear\Api\Services\DirectMessageService;
use Sinclear\Api\Services\MessageReactionService;
use Sinclear\Api\Services\NotificationPreferenceService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\RateLimiter;
use Sinclear\Api\Tests\Unit\FakeCentrifugoClient;

/**
 * DB-dependent integration test for CentrifugoProxyController.
 *
 * Runs on server via update.sh (requires MySQL/MariaDB).
 * Skips automatically when DB is unavailable.
 */
class CentrifugoProxyTest extends TestCase
{
    private PDO $db;
    private CentrifugoProxyController $controller;

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
        $this->db->exec("DROP TABLE IF EXISTS MessageReaction");
        $this->db->exec("DROP TABLE IF EXISTS DirectMessage");
        $this->db->exec("DROP TABLE IF EXISTS ChatParticipant");
        $this->db->exec("DROP TABLE IF EXISTS ChatConversation");
        $this->db->exec("DROP TABLE IF EXISTS UserActivity");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Minimal User table
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

        // Chat tables (subset needed for proxy tests)
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
                replyToMessageId varchar(191) DEFAULT NULL,
                editedAt datetime(3) DEFAULT NULL,
                deletedAt datetime(3) DEFAULT NULL,
                deletedBy varchar(191) DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_dm_seq (seq),
                KEY idx_dm_conversation_seq (conversationId, seq),
                KEY idx_dm_sender (senderId),
                KEY idx_dm_reply_to (replyToMessageId),
                CONSTRAINT fk_dm_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE,
                CONSTRAINT fk_dm_sender FOREIGN KEY (senderId) REFERENCES User (id) ON DELETE CASCADE,
                CONSTRAINT fk_dm_reply_to FOREIGN KEY (replyToMessageId) REFERENCES DirectMessage (id) ON DELETE SET NULL
            )
        ");

        // Seed users
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-1', 'u1@test.de', 'x', 'Alice')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-2', 'u2@test.de', 'x', 'Bob')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-3', 'u3@test.de', 'x', 'Charlie')");

        $this->db->exec("
            CREATE TABLE MessageReaction (
                id varchar(191) NOT NULL PRIMARY KEY,
                messageId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                emoji varchar(32) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_reaction_msg_user_emoji (messageId, userId, emoji),
                KEY idx_reaction_message (messageId),
                CONSTRAINT fk_reaction_message FOREIGN KEY (messageId) REFERENCES DirectMessage (id) ON DELETE CASCADE,
                CONSTRAINT fk_reaction_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        // Notification tables (needed by DirectMessageService::sendMessage)
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
                lastSeenAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                lastSuccessAt datetime(3) DEFAULT NULL,
                lastErrorAt datetime(3) DEFAULT NULL,
                lastError varchar(255) DEFAULT NULL,
                consecutiveFailures int NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY idx_pushsub_endpoint (endpoint(255)),
                KEY idx_pushsub_user (userId),
                KEY idx_pushsub_last_seen (lastSeenAt),
                KEY idx_pushsub_failures (consecutiveFailures),
                CONSTRAINT fk_push_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        $participantRepo = new ChatParticipantRepository($this->db);
        $messageRepo = new DirectMessageRepository($this->db);
        $reactionService = new MessageReactionService(
            new MessageReactionRepository($this->db),
            $messageRepo,
        );
        $fakeClient = new FakeCentrifugoClient();
        $messageService = new DirectMessageService(
            conversationRepo: new ChatConversationRepository($this->db),
            participantRepo: $participantRepo,
            messageRepo: $messageRepo,
            userRepo: new UserRepository($this->db),
            notificationService: new NotificationService(
                notificationRepo: new NotificationRepository($this->db),
                pushSubRepo: new PushSubscriptionRepository($this->db),
                preferenceService: new NotificationPreferenceService(
                    new NotificationPreferenceRepository($this->db)
                ),
                logger: new \Psr\Log\NullLogger(),
            ),
            rateLimiter: new RateLimiter(),
            centrifugoClient: $fakeClient,
            eventPublisher: new ChatEventPublisher(client: $fakeClient, logger: new \Psr\Log\NullLogger()),
            reactionService: $reactionService,
        );
        $this->controller = new CentrifugoProxyController(
            participantRepo: $participantRepo,
            rateLimiter: new RateLimiter(),
            reactionService: $reactionService,
            messageService: $messageService,
        );
    }

    // ── subscribe tests ──

    public function testSubscribeReturns200ForParticipant(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody(['user' => 'user-1', 'channel' => 'chat:conv-1']);
        $response = new Response();

        $result = $this->controller->subscribe($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('result', $body);
    }

    public function testSubscribeReturns403ForNonParticipant(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody(['user' => 'user-3', 'channel' => 'chat:conv-1']);
        $response = new Response();

        $result = $this->controller->subscribe($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testSubscribeReturns400ForInvalidChannel(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody(['user' => 'user-1', 'channel' => 'invalid-channel']);
        $response = new Response();

        $result = $this->controller->subscribe($request, $response);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function testSubscribeReturns403ForMissingUser(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody(['channel' => 'chat:conv-1']);
        $response = new Response();

        $result = $this->controller->subscribe($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testSubscribeReturns400ForEmptyBody(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody(null);
        $response = new Response();

        $result = $this->controller->subscribe($request, $response);

        $this->assertSame(400, $result->getStatusCode());
    }

    // ── publish tests ──

    public function testPublishReturns200ForParticipantWithTyping(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['typing' => true],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame(['typing' => true], $body['result']['data']);
        $this->assertTrue($body['result']['skip_history']);
    }

    public function testPublishSanitizesPayloadToTypingOnly(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['typing' => true, 'malicious' => 'injected'],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayNotHasKey('malicious', $body['result']['data']);
        $this->assertSame(['typing' => true], $body['result']['data']);
    }

    public function testPublishReturns403ForNonParticipant(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-3',
                'channel' => 'chat:conv-1',
                'data' => ['typing' => true],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testPublishReturns400ForInvalidChannel(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'bad:channel',
                'data' => ['typing' => true],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
    }

    // ── reaction tests ──

    public function testPublishReactionAddsAndReturnsSummary(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);
        $this->createMessage('msg-1', 'conv-1', 'user-1');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-2',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => '👍', 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('reaction_updated', $body['result']['data']['type']);
        $this->assertSame('msg-1', $body['result']['data']['messageId']);
        $this->assertTrue($body['result']['skip_history']);
        $this->assertSame('👍', $body['result']['data']['reactions'][0]['emoji']);
        $this->assertSame(1, $body['result']['data']['reactions'][0]['count']);
        $this->assertSame('user-2', $body['result']['data']['reactions'][0]['users'][0]['id']);

        $count = (int) $this->db->query("SELECT COUNT(*) FROM MessageReaction WHERE messageId = 'msg-1'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testPublishReactionRemoveClearsSummary(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);
        $this->createMessage('msg-1', 'conv-1', 'user-1');
        $this->db->exec("INSERT INTO MessageReaction (id, messageId, userId, emoji) VALUES ('r-1', 'msg-1', 'user-2', '👍')");

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-2',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => '👍', 'add' => false]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame([], $body['result']['data']['reactions']);
        $count = (int) $this->db->query("SELECT COUNT(*) FROM MessageReaction WHERE messageId = 'msg-1'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPublishReactionNormalizesVariationSelector(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);
        $this->createMessage('msg-1', 'conv-1', 'user-1');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-2',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => "❤\u{FE0F}", 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('❤', $body['result']['data']['reactions'][0]['emoji']);
    }

    public function testPublishReactionInvalidEmojiReturns400(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);
        $this->createMessage('msg-1', 'conv-1', 'user-1');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => 'free-text', 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_emoji', $body['error']);
    }

    public function testPublishReactionUnknownMessageReturns400(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'missing', 'emoji' => '👍', 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('message_not_found', $body['error']);
    }

    public function testPublishReactionDeletedMessageReturns400(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);
        $this->createMessage('msg-1', 'conv-1', 'user-1', deleted: true);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => '👍', 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('message_deleted', $body['error']);
    }

    public function testPublishReactionCannotCrossConversation(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);
        $this->createConversationWithParticipants('conv-2', ['user-1']);
        $this->createMessage('msg-1', 'conv-2', 'user-1');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['reaction' => ['messageId' => 'msg-1', 'emoji' => '👍', 'add' => true]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('message_not_found', $body['error']);
    }

    // ── message tests ──

    public function testPublishMessagePersistsAndReturnsCreatedEvent(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['message' => ['clientId' => 'c-1', 'type' => 'text', 'content' => 'Hallo Welt']],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('message_created', $body['result']['data']['type']);
        $this->assertSame('Hallo Welt', $body['result']['data']['message']['content']);
        $this->assertFalse($body['result']['data']['message']['deleted']);
        // Not ephemeral: history/recovery must keep the message.
        $this->assertArrayNotHasKey('skip_history', $body['result']);

        $count = (int) $this->db->query("SELECT COUNT(*) FROM DirectMessage WHERE conversationId = 'conv-1'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testPublishMessageReplyEmbedsTruncatedQuote(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1', 'user-2']);
        $this->createMessage('parent-1', 'conv-1', 'user-2', content: str_repeat('a', 200));

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['message' => [
                    'clientId' => 'c-2',
                    'type' => 'text',
                    'content' => 'Antwort',
                    'replyToMessageId' => 'parent-1',
                ]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $message = $body['result']['data']['message'];
        $this->assertSame('parent-1', $message['replyToMessageId']);
        $this->assertSame('parent-1', $message['replyTo']['id']);
        $this->assertSame('user-2', $message['replyTo']['senderId']);
        $this->assertFalse($message['replyTo']['deleted']);
        $this->assertTrue(str_ends_with($message['replyTo']['content'], '…'));
        $this->assertSame(str_repeat('a', 150) . '…', $message['replyTo']['content']);
    }

    public function testPublishMessageReplyCrossConversationReturns400(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);
        $this->createConversationWithParticipants('conv-2', ['user-1']);
        $this->createMessage('parent-2', 'conv-2', 'user-1');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['message' => [
                    'clientId' => 'c-3',
                    'content' => 'Antwort',
                    'replyToMessageId' => 'parent-2',
                ]],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('reply_not_found', $body['error']);
    }

    public function testPublishUnknownPayloadReturns400(): void
    {
        $this->createConversationWithParticipants('conv-1', ['user-1']);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/centrifugo/proxy')
            ->withParsedBody([
                'user' => 'user-1',
                'channel' => 'chat:conv-1',
                'data' => ['foo' => 'bar'],
            ]);
        $response = new Response();

        $result = $this->controller->publish($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_publish', $body['error']);
    }

    private function createConversationWithParticipants(string $convId, array $userIds): void
    {
        $this->db->exec("INSERT INTO ChatConversation (id, type, name) VALUES ('$convId', 'direct', NULL)");
        foreach ($userIds as $userId) {
            $this->db->exec("INSERT INTO ChatParticipant (conversationId, userId) VALUES ('$convId', '$userId')");
        }
    }

    private function createMessage(string $messageId, string $convId, string $senderId, bool $deleted = false, string $content = 'Hallo'): void
    {
        $deletedAt = $deleted ? 'NOW(3)' : 'NULL';
        $stmt = $this->db->prepare(
            "INSERT INTO DirectMessage (id, conversationId, senderId, type, content, deletedAt)
             VALUES (?, ?, ?, 'text', ?, $deletedAt)"
        );
        $stmt->execute([$messageId, $convId, $senderId, $content]);
    }
}
