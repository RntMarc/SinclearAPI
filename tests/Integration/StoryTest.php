<?php

namespace Sinclear\Api\Tests\Integration;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sinclear\Api\Controllers\StoryController;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Repository\StoryRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\StoryPolicy;
use Sinclear\Api\Services\ImageService;
use Sinclear\Api\Services\NotificationService;

class StoryTest extends TestCase
{
    private const IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private PDO $db;
    private StoryController $controller;
    private StoryRepository $repo;

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
        $this->db->exec("DROP TABLE IF EXISTS StoryView");
        $this->db->exec("DROP TABLE IF EXISTS Story");
        $this->db->exec("DROP TABLE IF EXISTS Notification");
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS NotificationPreference");
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
            CREATE TABLE StoryView (
                id varchar(191) NOT NULL,
                storyId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                viewedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                UNIQUE KEY uq_story_view_story_user (storyId, userId),
                KEY idx_story_view_user (userId),
                CONSTRAINT fk_story_view_story FOREIGN KEY (storyId) REFERENCES Story (id) ON DELETE CASCADE,
                CONSTRAINT fk_story_view_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
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

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-1', 'a@test.com', 'hash', 'Alice', NOW(3))");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, createdAt) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', NOW(3))");

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

        $this->repo = new StoryRepository($this->db);
        $logger = new Logger('test', [new NullHandler()]);
        $prefRepo = new \Sinclear\Api\Repository\NotificationPreferenceRepository($this->db);
        $prefService = new \Sinclear\Api\Services\NotificationPreferenceService($prefRepo);
        $notificationService = new NotificationService(
            notificationRepo: new NotificationRepository($this->db),
            pushSubRepo: new PushSubscriptionRepository($this->db),
            preferenceService: $prefService,
        );
        $this->controller = new StoryController(
            storyRepo: $this->repo,
            policy: new StoryPolicy(),
            imageService: new ImageService($logger),
            userRepo: new UserRepository($this->db),
            notificationService: $notificationService,
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("DROP TABLE IF EXISTS StoryView");
        $this->db->exec("DROP TABLE IF EXISTS Story");
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

    private function createStory(string $userId = 'user-1', ?string $caption = null, ?string $expiresAt = null): string
    {
        $id = $this->repo->create([
            'userId' => $userId,
            'image' => self::IMAGE_BASE64,
            'caption' => $caption,
        ]);

        if ($expiresAt !== null) {
            $stmt = $this->db->prepare('UPDATE Story SET expiresAt = ? WHERE id = ?');
            $stmt->execute([$expiresAt, $id]);
        }

        return $id;
    }

    // ── POST /stories ─────────────────────────────────────

    public function testCreateStoryReturns201(): void
    {
        $request = $this->requestWithUser('POST', '/stories', [
            'image' => self::IMAGE_BASE64,
            'caption' => 'Mein erster Tag',
        ]);
        $response = $this->controller->create($request, new Response());

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('Mein erster Tag', $data['data']['caption']);
        $this->assertFalse($data['data']['viewed']);
        $this->assertSame(0, $data['data']['viewCount']);
        $this->assertNotNull($data['data']['expiresAt']);
        $this->assertSame('user-1', $data['data']['user']['id']);
    }

    public function testCreateStoryNotifiesOtherUsers(): void
    {
        $request = $this->requestWithUser('POST', '/stories', ['image' => self::IMAGE_BASE64]);
        $response = $this->controller->create($request, new Response());
        $this->assertSame(201, $response->getStatusCode());

        $stmt = $this->db->prepare('SELECT userId, type, data FROM Notification ORDER BY userId');
        $stmt->execute();
        $notifications = $stmt->fetchAll();

        $this->assertCount(1, $notifications);
        $this->assertSame('user-2', $notifications[0]['userId']);
        $this->assertSame('story_post', $notifications[0]['type']);

        $data = json_decode($notifications[0]['data'], true);
        $relations = array_column($data, 'relation');
        $this->assertContains('story_author', $relations);
        $this->assertContains('story', $relations);
    }

    public function testCreateStoryWithoutImageReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/stories', ['caption' => 'ohne Bild']);
        $response = $this->controller->create($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_image', $data['error']);
    }

    public function testCreateStoryWithInvalidImageReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/stories', ['image' => 'not-base64!!']);
        $response = $this->controller->create($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateStoryWithTooLongCaptionReturns400(): void
    {
        $request = $this->requestWithUser('POST', '/stories', [
            'image' => self::IMAGE_BASE64,
            'caption' => str_repeat('x', 1001),
        ]);
        $response = $this->controller->create($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_caption', $data['error']);
    }

    // ── GET /stories ──────────────────────────────────────

    public function testFeedGroupsStoriesByUser(): void
    {
        $this->createStory('user-1', 'Story 1');
        $this->createStory('user-1', 'Story 2');
        $this->createStory('user-2', 'Story 3');

        $request = $this->requestWithUser('GET', '/stories');
        $response = $this->controller->feed($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $data['data']);

        $alice = $data['data'][0]['userId'] === 'user-1' ? $data['data'][0] : $data['data'][1];
        $this->assertSame('Alice', $alice['displayName']);
        $this->assertCount(2, $alice['stories']);
    }

    public function testFeedExcludesExpiredStories(): void
    {
        $this->createStory('user-1', 'aktiv');
        $this->createStory('user-2', 'abgelaufen', '2000-01-01 00:00:00.000');

        $request = $this->requestWithUser('GET', '/stories');
        $response = $this->controller->feed($request, new Response());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame('user-1', $data['data'][0]['userId']);
    }

    public function testFeedMarksViewedStories(): void
    {
        $storyId = $this->createStory('user-1');
        $this->repo->markViewed($storyId, 'user-2');

        $request = $this->requestWithUser('GET', '/stories', userId: 'user-2');
        $response = $this->controller->feed($request, new Response());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['data'][0]['stories'][0]['viewed']);

        $request = $this->requestWithUser('GET', '/stories', userId: 'user-1');
        $response = $this->controller->feed($request, new Response());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertFalse($data['data'][0]['stories'][0]['viewed']);
    }

    // ── GET /stories/{id} ─────────────────────────────────

    public function testGetStoryReturnsDetails(): void
    {
        $storyId = $this->createStory('user-1', 'Details');
        $this->repo->markViewed($storyId, 'user-2');

        $request = $this->requestWithUser('GET', '/stories/' . $storyId, userId: 'user-2');
        $response = $this->controller->get($request, new Response(), ['id' => $storyId]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['data']['viewed']);
        $this->assertSame(1, $data['data']['viewCount']);
        $this->assertSame('Alice', $data['data']['user']['displayName']);
    }

    public function testGetUnknownStoryReturns404(): void
    {
        $request = $this->requestWithUser('GET', '/stories/unknown');
        $response = $this->controller->get($request, new Response(), ['id' => 'unknown']);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── DELETE /stories/{id} ──────────────────────────────

    public function testDeleteOwnStoryReturns204(): void
    {
        $storyId = $this->createStory('user-1');

        $request = $this->requestWithUser('DELETE', '/stories/' . $storyId);
        $response = $this->controller->delete($request, new Response(), ['id' => $storyId]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->repo->findById($storyId));
    }

    public function testDeleteForeignStoryReturns403(): void
    {
        $storyId = $this->createStory('user-1');

        $request = $this->requestWithUser('DELETE', '/stories/' . $storyId, userId: 'user-2');
        $response = $this->controller->delete($request, new Response(), ['id' => $storyId]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->repo->findById($storyId));
    }

    public function testDeleteUnknownStoryReturns404(): void
    {
        $request = $this->requestWithUser('DELETE', '/stories/unknown');
        $response = $this->controller->delete($request, new Response(), ['id' => 'unknown']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteStoryRemovesViews(): void
    {
        $storyId = $this->createStory('user-1');
        $this->repo->markViewed($storyId, 'user-2');

        $request = $this->requestWithUser('DELETE', '/stories/' . $storyId);
        $this->controller->delete($request, new Response(), ['id' => $storyId]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM StoryView WHERE storyId = ?');
        $stmt->execute([$storyId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ── POST /stories/{id}/view ───────────────────────────

    public function testMarkViewedReturns200(): void
    {
        $storyId = $this->createStory('user-1');

        $request = $this->requestWithUser('POST', '/stories/' . $storyId . '/view', userId: 'user-2');
        $response = $this->controller->markViewed($request, new Response(), ['id' => $storyId]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['data']['viewed']);
        $this->assertSame(['viewed'], array_keys($data['data']));
    }

    public function testMarkViewedIsIdempotent(): void
    {
        $storyId = $this->createStory('user-1');

        $request = $this->requestWithUser('POST', '/stories/' . $storyId . '/view', userId: 'user-2');
        $this->controller->markViewed($request, new Response(), ['id' => $storyId]);
        $this->controller->markViewed($request, new Response(), ['id' => $storyId]);

        $this->assertSame(1, $this->repo->countViews($storyId));
    }

    public function testMarkViewedUnknownStoryReturns404(): void
    {
        $request = $this->requestWithUser('POST', '/stories/unknown/view');
        $response = $this->controller->markViewed($request, new Response(), ['id' => 'unknown']);

        $this->assertSame(404, $response->getStatusCode());
    }
}
