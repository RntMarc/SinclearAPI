<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinclear\Api\Controllers\CentrifugoProxyController;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Services\RateLimiter;

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
        $this->db->exec("DROP TABLE IF EXISTS DirectMessage");
        $this->db->exec("DROP TABLE IF EXISTS ChatParticipant");
        $this->db->exec("DROP TABLE IF EXISTS ChatConversation");
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

        // Seed users
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-1', 'u1@test.de', 'x', 'Alice')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-2', 'u2@test.de', 'x', 'Bob')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-3', 'u3@test.de', 'x', 'Charlie')");

        $participantRepo = new ChatParticipantRepository($this->db);
        $this->controller = new CentrifugoProxyController(
            participantRepo: $participantRepo,
            rateLimiter: new RateLimiter(),
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

    private function createConversationWithParticipants(string $convId, array $userIds): void
    {
        $this->db->exec("INSERT INTO ChatConversation (id, type, name) VALUES ('$convId', 'direct', NULL)");
        foreach ($userIds as $userId) {
            $this->db->exec("INSERT INTO ChatParticipant (conversationId, userId) VALUES ('$convId', '$userId')");
        }
    }
}
