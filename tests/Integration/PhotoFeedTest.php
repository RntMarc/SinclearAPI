<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Controllers\PhotoController;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\ExternalDataCacheRepository;
use Sinclear\Api\Repository\PhotoRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\UserPolicy;
use Sinclear\Api\Services\UnsplashService;

/**
 * Integrationstests für den Foto-Feed. Der Abruf von Unsplash wird über den
 * vorab befüllten ExternalDataCache umgangen (der UnsplashService liest
 * zuerst aus dem Cache), sodass keine echten HTTP-Aufrufe nötig sind.
 *
 * Hinweis: Diese Tests benötigen eine MySQL-Testdatenbank und laufen daher
 * nur auf dem Server (siehe AGENTS.md).
 */
class PhotoFeedTest extends TestCase
{
    private PDO $db;
    private PhotoController $controller;

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

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['CloseFriend', 'UserPreferences', 'SocialInfo', 'ExternalDataCache', 'UserActivity', 'User'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
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
            CREATE TABLE SocialInfo (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                unsplashHandle varchar(191) DEFAULT NULL,
                UNIQUE KEY idx_social_user (userId)
            )
        ');
        $this->db->exec('
            CREATE TABLE UserPreferences (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                unsplashVisibility tinyint NOT NULL DEFAULT 1,
                UNIQUE KEY idx_prefs_user (userId)
            )
        ');
        $this->db->exec('
            CREATE TABLE CloseFriend (
                userId varchar(191) NOT NULL,
                friendId varchar(191) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (userId, friendId)
            )
        ');
        $this->db->exec("
            CREATE TABLE ExternalDataCache (
                id varchar(191) NOT NULL,
                data_type varchar(64) NOT NULL,
                location_key varchar(191) NOT NULL,
                source enum('infranode','open-meteo','mixed','unsplash') NOT NULL,
                payload json NOT NULL,
                expires_at datetime(3) NOT NULL,
                created_at datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                UNIQUE KEY uk_cache_type_location (data_type, location_key)
            )
        ");

        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');
        $this->seedUser('user-3', 'Carol');
        $this->seedUser('user-4', 'Dave');
        $this->seedUser('user-5', 'Eve');

        $this->seedSocial('user-1', 'alice');
        $this->seedSocial('user-2', 'bob');
        $this->seedSocial('user-3', 'carol');

        $this->seedVisibility('user-1', 1);
        $this->seedVisibility('user-2', 0);
        $this->seedVisibility('user-3', 2);

        // Carol erlaubt Dave ihre Fotos (Dave ist enger Freund von Carol).
        $this->db->exec("INSERT INTO CloseFriend (userId, friendId) VALUES ('user-3', 'user-4')");

        $this->seedCache('alice', [
            $this->photo('p-a1', '2026-01-01 10:00:00'),
            $this->photo('p-a2', '2026-01-03 10:00:00'),
        ]);
        $this->seedCache('bob', [
            $this->photo('p-b1', '2026-01-04 10:00:00'),
        ]);
        $this->seedCache('carol', [
            $this->photo('p-c1', '2026-01-02 10:00:00'),
        ]);

        $settings = new Settings(
            app: [],
            db: [],
            jwt: [],
            discord: [],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
            unsplash: [
                'base_url' => 'https://api.unsplash.com',
                'access_key' => 'test-key',
                'per_user_limit' => 30,
                'cache_ttl' => 3600,
            ],
        );

        $logger = new Logger('test', [new NullHandler()]);
        $unsplash = new UnsplashService(
            cacheRepo: new ExternalDataCacheRepository($this->db),
            settings: $settings,
            logger: $logger,
        );

        $this->controller = new PhotoController(
            photoRepo: new PhotoRepository($this->db),
            unsplashService: $unsplash,
            userPolicy: new UserPolicy(closeFriendRepo: new CloseFriendRepository($this->db)),
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['CloseFriend', 'UserPreferences', 'SocialInfo', 'ExternalDataCache', 'UserActivity', 'User'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedUser(string $id, string $displayName): void
    {
        $stmt = $this->db->prepare('INSERT INTO User (id, email, passwordHash, displayName) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $id . '@test.com', 'hash', $displayName]);
    }

    private function seedSocial(string $userId, string $handle): void
    {
        $stmt = $this->db->prepare('INSERT INTO SocialInfo (id, userId, unsplashHandle) VALUES (?, ?, ?)');
        $stmt->execute(['social-' . $userId, $userId, $handle]);
    }

    private function seedVisibility(string $userId, int $visibility): void
    {
        $stmt = $this->db->prepare('INSERT INTO UserPreferences (id, userId, unsplashVisibility) VALUES (?, ?, ?)');
        $stmt->execute(['pref-' . $userId, $userId, $visibility]);
    }

    /** @param list<array<string, mixed>> $photos */
    private function seedCache(string $handle, array $photos): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ExternalDataCache (id, data_type, location_key, source, payload, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $stmt->execute([
            'cache-' . $handle,
            'unsplash_user_photos',
            $handle,
            'unsplash',
            json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string, mixed> */
    private function photo(string $id, string $createdAt): array
    {
        return [
            'id' => $id,
            'thumb' => "https://images.unsplash.com/{$id}?w=400",
            'regular' => "https://images.unsplash.com/{$id}?w=1080",
            'width' => 100,
            'height' => 100,
            'createdAt' => $createdAt,
            'photographer' => [
                'name' => 'Photographer',
                'username' => 'photographer',
                'url' => 'https://unsplash.com/@photographer',
            ],
        ];
    }

    private function requestWithUser(string $method, string $path, string $userId = 'user-1'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        $user = new AuthenticatedUser(id: $userId, email: 'test@test.com', isAdmin: false, jti: 'test-jti');
        return $request->withAttribute(AuthenticatedUser::class, $user);
    }

    /** @return array<string, mixed> */
    private function feed(string $userId, array $query = []): array
    {
        $path = '/photos';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        $request = $this->requestWithUser('GET', $path, $userId);
        $response = $this->controller->feed($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true);
    }

    public function testFeedRespectsVisibility(): void
    {
        // Dave (user-4): sieht öffentliche (Alice) + enge-Freunde-Fotos (Carol),
        // nicht Bobs „nur ich"-Fotos.
        $ids = array_column($this->feed('user-4')['data'], 'id');
        $this->assertContains('p-a1', $ids);
        $this->assertContains('p-a2', $ids);
        $this->assertContains('p-c1', $ids);
        $this->assertNotContains('p-b1', $ids);

        // Eve (user-5): nur öffentliche Fotos (Alice).
        $ids = array_column($this->feed('user-5')['data'], 'id');
        $this->assertContains('p-a1', $ids);
        $this->assertNotContains('p-b1', $ids);
        $this->assertNotContains('p-c1', $ids);
    }

    public function testOwnPhotosAreAlwaysVisible(): void
    {
        // Bob hat „nur ich" — sieht seine eigenen Fotos trotzdem.
        $ids = array_column($this->feed('user-2')['data'], 'id');
        $this->assertContains('p-b1', $ids);
    }

    public function testFeedIsSortedNewestFirst(): void
    {
        $ids = array_column($this->feed('user-4')['data'], 'id');
        $this->assertSame(['p-a2', 'p-c1', 'p-a1'], $ids);
    }

    public function testFeedIncludesAuthorAndPhotographerCredit(): void
    {
        $data = $this->feed('user-4')['data'];
        $first = $data[0];

        $this->assertSame('user-1', $first['author']['id']);
        $this->assertSame('Alice', $first['author']['displayName']);
        $this->assertSame('Photographer', $first['photographer']['name']);
        $this->assertSame('photographer', $first['photographer']['username']);
    }

    public function testFeedPaginationMeta(): void
    {
        $page1 = $this->feed('user-4', ['page' => 1, 'limit' => 1]);
        $this->assertCount(1, $page1['data']);
        $this->assertSame('p-a2', $page1['data'][0]['id']);
        $this->assertSame(['page' => 1, 'limit' => 1, 'total' => 3, 'totalPages' => 3], $page1['meta']);

        $page2 = $this->feed('user-4', ['page' => 2, 'limit' => 1]);
        $this->assertSame('p-c1', $page2['data'][0]['id']);
        $this->assertSame(2, $page2['meta']['page']);
    }

    public function testUserPhotosRespectsVisibility(): void
    {
        // Dave darf Carols Fotos sehen.
        $request = $this->requestWithUser('GET', '/photos/user/user-3', 'user-4');
        $response = $this->controller->userPhotos($request, new Response(), ['id' => 'user-3']);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame(['p-c1'], array_column($data['data'], 'id'));

        // Eve darf Carols Fotos nicht sehen (keine enge Freundschaft).
        $request = $this->requestWithUser('GET', '/photos/user/user-3', 'user-5');
        $response = $this->controller->userPhotos($request, new Response(), ['id' => 'user-3']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserPhotosUnknownUserReturns404(): void
    {
        // Dave hat keinen Unsplash-Handle.
        $request = $this->requestWithUser('GET', '/photos/user/user-4', 'user-1');
        $response = $this->controller->userPhotos($request, new Response(), ['id' => 'user-4']);
        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('user_not_found', $data['error']);
    }
}
