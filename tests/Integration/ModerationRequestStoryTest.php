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

class ModerationRequestStoryTest extends TestCase
{
    private PDO $db;
    private ModerationRequestController $controller;
    private StoryRepository $storyRepo;

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

        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS ModerationRequest");
        $this->db->exec("DROP TABLE IF EXISTS StoryView");
        $this->db->exec("DROP TABLE IF EXISTS Story");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

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
            CREATE TABLE Story (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                image longtext NOT NULL,
                caption text DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                expiresAt datetime(3) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_story_user_created (userId, createdAt),
                KEY idx_story_expires (expiresAt),
                CONSTRAINT fk_story_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE ModerationRequest (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                requestType varchar(191) NOT NULL,
                objectType varchar(191) NOT NULL,
                objectId varchar(191) NOT NULL,
                message text NOT NULL,
                status varchar(191) NOT NULL DEFAULT 'unread',
                adminComment text DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-1', 'a@test.com', 'hash', 'Alice', NOW(3))");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', NOW(3))");

        $this->storyRepo = new StoryRepository($this->db);

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
            storyRepo: $this->storyRepo,
        );

        $this->controller = new ModerationRequestController($service);
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS ModerationRequest");
        $this->db->exec("DROP TABLE IF EXISTS StoryView");
        $this->db->exec("DROP TABLE IF EXISTS Story");
        $this->db->exec("DROP TABLE IF EXISTS User");
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWithUser(string $path, array $body, string $userId = 'user-1'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
        $request = $request->withParsedBody($body);
        $user = new AuthenticatedUser(id: $userId, email: 'test@test.com', isAdmin: false, jti: 'test-jti');
        return $request->withAttribute(AuthenticatedUser::class, $user);
    }

    private function createStory(string $userId = 'user-1'): string
    {
        return $this->storyRepo->create([
            'userId' => $userId,
            'image' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'caption' => null,
        ]);
    }

    private function create(string $userId, string $requestType, string $objectId): ResponseInterface
    {
        $request = $this->requestWithUser('/moderation-requests', [
            'requestType' => $requestType,
            'objectType' => 'story',
            'objectId' => $objectId,
            'message' => 'Anmerkung zur Story',
        ], $userId);
        return $this->controller->create($request, new Response());
    }

    public function testReportForeignStoryReturns201(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-2', 'report', $storyId);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('story', $data['data']['objectType']);
        $this->assertSame('report', $data['data']['requestType']);
    }

    public function testReportOwnStoryReturns403(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-1', 'report', $storyId);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('cannot_report_own', $data['error']);
    }

    public function testCommentForeignStoryReturns201(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-2', 'other', $storyId);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCommentOwnStoryReturns201(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-1', 'other', $storyId);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testDeletionOwnStoryReturns400(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-1', 'deletion', $storyId);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('deletion_not_supported', $data['error']);
    }

    public function testDeletionForeignStoryReturns400(): void
    {
        $storyId = $this->createStory('user-1');
        $response = $this->create('user-2', 'deletion', $storyId);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('deletion_not_supported', $data['error']);
    }

    public function testUnknownStoryReturns404(): void
    {
        $response = $this->create('user-2', 'report', 'unknown-story');

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('object_not_found', $data['error']);
    }
}
