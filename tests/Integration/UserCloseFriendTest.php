<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sinclear\Api\Controllers\UserController;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\UserPreferenceRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\UserPolicy;
use Sinclear\Api\Services\UserPreferenceService;
use Sinclear\Api\Services\UserService;

/**
 * Tests für die Verwaltung der Enge-Freunde-Beziehung (CloseFriend):
 * Repository-Idempotenz, ausgehende Richtung des isCloseFriend-Flags und
 * die Auswirkung einer Markierung auf die Sichtbarkeit von Level-2-Daten.
 *
 * Hinweis: Diese Tests benötigen eine MySQL-Testdatenbank und laufen daher
 * nur auf dem Server (siehe AGENTS.md).
 */
class UserCloseFriendTest extends TestCase
{
    private PDO $db;
    private CloseFriendRepository $closeFriendRepo;
    private UserPreferenceService $preferenceService;
    private UserService $userService;
    private UserController $controller;

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
        foreach (['CloseFriend', 'UserPreferences', 'User'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

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
            CREATE TABLE UserPreferences (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL UNIQUE,
                language varchar(16) NOT NULL DEFAULT 'de',
                theme varchar(16) NOT NULL DEFAULT 'light',
                primaryColor varchar(16) NOT NULL DEFAULT '#6366f1',
                timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
                emailVisibility tinyint NOT NULL DEFAULT 1,
                birthdayVisibility tinyint NOT NULL DEFAULT 1,
                syncAvatarFromDiscord tinyint NOT NULL DEFAULT 1,
                onboardingCompleted tinyint NOT NULL DEFAULT 0,
                discordVisibility tinyint NOT NULL DEFAULT 1,
                fluxerVisibility tinyint NOT NULL DEFAULT 1,
                matrixVisibility tinyint NOT NULL DEFAULT 1,
                signalVisibility tinyint NOT NULL DEFAULT 1,
                whatsappVisibility tinyint NOT NULL DEFAULT 1,
                unsplashVisibility tinyint NOT NULL DEFAULT 1,
                instagramVisibility tinyint NOT NULL DEFAULT 1,
                mastodonVisibility tinyint NOT NULL DEFAULT 1,
                pixelfedVisibility tinyint NOT NULL DEFAULT 1,
                blueskyVisibility tinyint NOT NULL DEFAULT 1,
                youtubeVisibility tinyint NOT NULL DEFAULT 1,
                twitchVisibility tinyint NOT NULL DEFAULT 1,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");

        $this->db->exec("
            CREATE TABLE CloseFriend (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                friendId varchar(191) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY CloseFriend_userId_friendId_key (userId, friendId)
            )
        ");

        $this->closeFriendRepo = new CloseFriendRepository($this->db);
        $this->preferenceService = new UserPreferenceService(new UserPreferenceRepository($this->db));

        $this->userService = new UserService(
            new UserRepository($this->db),
            new ContactInfoRepository($this->db),
            new SocialInfoRepository($this->db),
            $this->preferenceService,
            new UserPolicy($this->closeFriendRepo),
            $this->closeFriendRepo,
        );

        $this->controller = new UserController($this->userService);
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['CloseFriend', 'UserPreferences', 'User'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedUser(string $id, string $displayName): void
    {
        $stmt = $this->db->prepare('INSERT INTO User (id, email, passwordHash, displayName) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $id . '@test.com', 'hash', $displayName]);
    }

    private function requestWithUser(string $method, string $path, string $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        $user = new AuthenticatedUser(id: $userId, email: $userId . '@test.com', isAdmin: false, jti: 'test-jti');
        return $request->withAttribute(AuthenticatedUser::class, $user);
    }

    /** @param array<string, string> $args */
    private function add(string $requesterId, string $friendId): Response
    {
        $request = $this->requestWithUser('PUT', '/user/me/close-friends/' . $friendId, $requesterId);
        $response = $this->controller->addCloseFriend($request, new Response(), ['userId' => $friendId]);
        return $response;
    }

    /** @param array<string, string> $args */
    private function remove(string $requesterId, string $friendId): Response
    {
        $request = $this->requestWithUser('DELETE', '/user/me/close-friends/' . $friendId, $requesterId);
        return $this->controller->removeCloseFriend($request, new Response(), ['userId' => $friendId]);
    }

    public function testAddIsIdempotent(): void
    {
        $this->closeFriendRepo->add('user-1', 'user-2');
        $this->closeFriendRepo->add('user-1', 'user-2');

        $this->assertSame(['user-2'], $this->closeFriendRepo->listFriendIdsFor('user-1'));
        $this->assertTrue($this->closeFriendRepo->isCloseFriend('user-1', 'user-2'));
    }

    public function testRemoveIsIdempotent(): void
    {
        // Entfernen einer nicht vorhandenen Markierung wirft keinen Fehler.
        $this->closeFriendRepo->remove('user-1', 'user-2');
        $this->assertSame([], $this->closeFriendRepo->listFriendIdsFor('user-1'));

        $this->closeFriendRepo->add('user-1', 'user-2');
        $this->closeFriendRepo->remove('user-1', 'user-2');
        $this->assertSame([], $this->closeFriendRepo->listFriendIdsFor('user-1'));
    }

    public function testIsCloseFriendFlagIsOutgoing(): void
    {
        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');

        // Alice markiert Bob (ausgehend: user-1 -> user-2).
        $this->closeFriendRepo->add('user-1', 'user-2');

        // Alice (user-1) sieht bei Bob isCloseFriend = true.
        $alice = new AuthenticatedUser(id: 'user-1', email: 'a@test.com', isAdmin: false, jti: 'j');
        $forAlice = $this->userService->listAll($alice);
        $bobForAlice = $this->findById($forAlice, 'user-2');
        $this->assertTrue($bobForAlice['isCloseFriend']);

        // Bob (user-2) hat Alice NICHT markiert -> bei Alice isCloseFriend = false.
        $bob = new AuthenticatedUser(id: 'user-2', email: 'b@test.com', isAdmin: false, jti: 'j');
        $forBob = $this->userService->listAll($bob);
        $aliceForBob = $this->findById($forBob, 'user-1');
        $this->assertFalse($aliceForBob['isCloseFriend']);
    }

    public function testListCloseFriendsReturnsMarkedUsers(): void
    {
        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');
        $this->seedUser('user-3', 'Carol');

        $this->closeFriendRepo->add('user-1', 'user-2');
        $this->closeFriendRepo->add('user-1', 'user-3');

        $request = $this->requestWithUser('GET', '/user/me/close-friends', 'user-1');
        $response = $this->controller->listCloseFriends($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(['user-2', 'user-3'], array_column($data['data'], 'id'));
        foreach ($data['data'] as $entry) {
            $this->assertTrue($entry['isCloseFriend']);
        }
    }

    public function testAddEndpointsValidationAndIdempotency(): void
    {
        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');

        // Erfolgreich.
        $response = $this->add('user-1', 'user-2');
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame(['userId' => 'user-2', 'isCloseFriend' => true], $data['data']);

        // Idempotent (kein 409/Fehler).
        $response = $this->add('user-1', 'user-2');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['user-2'], $this->closeFriendRepo->listFriendIdsFor('user-1'));

        // Sich selbst markieren -> 400.
        $response = $this->add('user-1', 'user-1');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('cannot_add_self', json_decode((string) $response->getBody(), true)['error']);

        // Unbekannter Zielnutzer -> 404.
        $response = $this->add('user-1', 'user-unknown');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('user_not_found', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testRemoveEndpoint(): void
    {
        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');

        $this->closeFriendRepo->add('user-1', 'user-2');

        $response = $this->remove('user-1', 'user-2');
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->closeFriendRepo->listFriendIdsFor('user-1'));

        // Idempotent bei nicht vorhandener Markierung.
        $response = $this->remove('user-1', 'user-2');
        $this->assertSame(204, $response->getStatusCode());

        // Unbekannter Zielnutzer -> 404.
        $response = $this->remove('user-1', 'user-unknown');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('user_not_found', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testMarkingRevealsLevel2Data(): void
    {
        $this->seedUser('user-1', 'Alice');
        $this->seedUser('user-2', 'Bob');

        // Alice erlaubt E-Mail nur engen Freunden (Level 2).
        $this->preferenceService->update('user-1', ['emailVisibility' => 2]);

        // Vor der Markierung sieht Bob Alices E-Mail nicht.
        $before = $this->getBase('user-2', 'user-1');
        $this->assertArrayNotHasKey('email', $before['data']);

        // Alice markiert Bob als engen Freund (ausgehend: user-1 -> user-2).
        $this->add('user-1', 'user-2');

        // Jetzt sieht Bob Alices E-Mail (enge-Freunde-Sichtbarkeit greift).
        $after = $this->getBase('user-2', 'user-1');
        $this->assertSame('user-1@test.com', $after['data']['email']);
    }

    /** @return array<string, mixed> */
    private function getBase(string $requesterId, string $targetId): array
    {
        $request = $this->requestWithUser('GET', '/user/' . $targetId . '/base', $requesterId);
        $response = $this->controller->getBase($request, new Response(), ['userId' => $targetId]);
        $this->assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param list<array<string, mixed>> $users
     * @return array<string, mixed>
     */
    private function findById(array $users, string $id): array
    {
        foreach ($users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        $this->fail("Nutzer $id nicht in der Liste gefunden.");
    }
}
