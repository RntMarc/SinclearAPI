<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sinclear\Api\Controllers\ModerationRequestController;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\DirectMessageRepository;
use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;
use Sinclear\Api\Repository\FeedbackCommentRepository;
use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\FeedPostCommentRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\ModerationRequestRepository;
use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Repository\RecipeReviewRepository;
use Sinclear\Api\Repository\StoryRepository;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTicketRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\ModerationRequestService;

/**
 * DB-dependent integration test for chat_message moderation requests.
 *
 * Runs on server via update.sh (requires MySQL/MariaDB).
 * Skips automatically when DB is unavailable.
 */
class ModerationRequestChatMessageTest extends TestCase
{
    private PDO $db;
    private ModerationRequestController $controller;
    private DirectMessageRepository $messageRepo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Database not available');
        }

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

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS ModerationRequest');
        $this->db->exec('DROP TABLE IF EXISTS DirectMessage');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec('
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
        ');

        $this->db->exec('
            CREATE TABLE DirectMessage (
                id varchar(191) NOT NULL PRIMARY KEY,
                seq bigint unsigned NOT NULL AUTO_INCREMENT,
                conversationId varchar(191) NOT NULL,
                senderId varchar(191) NOT NULL,
                type enum(\'text\',\'image\',\'location\') NOT NULL DEFAULT \'text\',
                content text NOT NULL,
                payload json DEFAULT NULL,
                clientId varchar(64) DEFAULT NULL,
                replyToMessageId varchar(191) DEFAULT NULL,
                editedAt datetime(3) DEFAULT NULL,
                deletedAt datetime(3) DEFAULT NULL,
                deletedBy varchar(191) DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_dm_seq (seq)
            )
        ');

        $this->db->exec('
            CREATE TABLE ModerationRequest (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                requestType varchar(191) NOT NULL,
                objectType varchar(191) NOT NULL,
                objectId varchar(191) NOT NULL,
                message text NOT NULL,
                status varchar(191) NOT NULL DEFAULT \'unread\',
                adminComment text DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            )
        ');

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-1', 'a@test.com', 'hash', 'Alice', NOW(3))");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', NOW(3))");

        $this->messageRepo = new DirectMessageRepository($this->db);

        $service = new ModerationRequestService(
            requestRepo: new ModerationRequestRepository($this->db),
            recipeRepo: new RecipeRepository($this->db),
            placeRepo: new DiscoverPlaceRepository($this->db),
            postRepo: new FeedPostRepository($this->db),
            userRepo: new UserRepository($this->db),
            recipeReviewRepo: new RecipeReviewRepository($this->db),
            feedPostCommentRepo: new FeedPostCommentRepository($this->db),
            discoverReviewRepo: new DiscoverReviewRepository($this->db),
            feedbackSuggestionRepo: new FeedbackSuggestionRepository($this->db),
            feedbackCommentRepo: new FeedbackCommentRepository($this->db),
            travelEventRepo: new TravelEventRepository($this->db),
            travelTicketRepo: new TravelTicketRepository($this->db),
            travelRelationRepo: new TravelRelationRepository($this->db),
            subscriptionRepo: new SubscriptionRepository($this->db),
            calendarEventRepo: new CalendarEventRepository($this->db),
            storyRepo: new StoryRepository($this->db),
            directMessageRepo: $this->messageRepo,
        );

        $this->controller = new ModerationRequestController($service);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS ModerationRequest');
        $this->db->exec('DROP TABLE IF EXISTS DirectMessage');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWithUser(string $path, array $body, string $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
        $request = $request->withParsedBody($body);
        $user = new AuthenticatedUser(id: $userId, email: 'test@test.com', isAdmin: false, jti: 'test-jti');
        return $request->withAttribute(AuthenticatedUser::class, $user);
    }

    private function createMessage(string $senderId = 'user-1', string $conversationId = 'conversation-1'): string
    {
        return $this->messageRepo->create([
            'conversationId' => $conversationId,
            'senderId' => $senderId,
            'type' => 'text',
            'content' => 'Nachricht, die gemeldet wird',
        ])['id'];
    }

    private function create(string $userId, string $requestType, string $objectId): ResponseInterface
    {
        $request = $this->requestWithUser('/moderation-requests', [
            'requestType' => $requestType,
            'objectType' => 'chat_message',
            'objectId' => $objectId,
            'message' => 'Anmerkung zur Chat-Nachricht',
        ], $userId);

        return $this->controller->create($request, new Response());
    }

    public function testReportForeignChatMessageReturns201(): void
    {
        $messageId = $this->createMessage('user-1');
        $response = $this->create('user-2', 'report', $messageId);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('chat_message', $data['data']['objectType']);
        $this->assertSame('report', $data['data']['requestType']);
        $this->assertSame($messageId, $data['data']['objectId']);
    }

    public function testReportOwnChatMessageReturns403(): void
    {
        $messageId = $this->createMessage('user-1');
        $response = $this->create('user-1', 'report', $messageId);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('cannot_report_own', $data['error']);
    }

    public function testDeletionOwnChatMessageReturns201(): void
    {
        $messageId = $this->createMessage('user-1');
        $response = $this->create('user-1', 'deletion', $messageId);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('deletion', $data['data']['requestType']);
    }

    public function testDeletionForeignChatMessageReturns403(): void
    {
        $messageId = $this->createMessage('user-1');
        $response = $this->create('user-2', 'deletion', $messageId);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('cannot_request_deletion_foreign', $data['error']);
    }

    public function testOtherOnForeignChatMessageReturns201(): void
    {
        $messageId = $this->createMessage('user-1');
        $response = $this->create('user-2', 'other', $messageId);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testUnknownChatMessageReturns404(): void
    {
        $response = $this->create('user-2', 'report', 'unknown-message');

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('object_not_found', $data['error']);
    }

    public function testDeletedChatMessageReturns404(): void
    {
        $messageId = $this->createMessage('user-1');
        $this->messageRepo->markDeleted($messageId, 'user-1');

        $response = $this->create('user-2', 'report', $messageId);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('object_not_found', $data['error']);
    }

    public function testDeletedChatMessageForOtherReturns404(): void
    {
        $messageId = $this->createMessage('user-1');
        $this->messageRepo->markDeleted($messageId, 'user-1');

        $response = $this->create('user-1', 'other', $messageId);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('object_not_found', $data['error']);
    }
}
