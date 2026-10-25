<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\GameAccountRepository;

final class GameAccountRepositoryTest extends TestCase
{
    private PDO $db;
    private GameAccountRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
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
        $this->db->exec('DROP TABLE IF EXISTS GameAccount');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec('CREATE TABLE User (id varchar(191) NOT NULL PRIMARY KEY)');

        $this->db->exec("
            CREATE TABLE GameAccount (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                game varchar(50) NOT NULL,
                accountId varchar(191) NOT NULL,
                displayName varchar(191) DEFAULT NULL,
                avatarUrl varchar(1024) DEFAULT NULL,
                profile JSON DEFAULT NULL,
                lastSyncedAt datetime(3) DEFAULT NULL,
                createdAt datetime(3) NOT NULL,
                updatedAt datetime(3) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY GameAccount_user_game_key (userId, game),
                KEY GameAccount_game_accountId_idx (game, accountId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->repo = new GameAccountRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS GameAccount');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testUpsertCreatesAndReplacesAccount(): void
    {
        $this->insertUser('user-1');

        $id = $this->repo->upsert(
            userId: 'user-1',
            game: 'deadlock',
            accountId: '179673173',
            displayName: 'Player',
            avatarUrl: 'https://example.test/avatar.jpg',
            profile: ['steamId64' => '76561197960265770'],
        );

        $account = $this->repo->findByUserIdAndGame('user-1', 'deadlock');
        self::assertNotNull($account);
        self::assertSame($id, $account['id']);
        self::assertSame('179673173', $account['accountId']);
        self::assertSame('Player', $account['displayName']);
        self::assertSame(['steamId64' => '76561197960265770'], $account['profile']);

        $replacedId = $this->repo->upsert(
            userId: 'user-1',
            game: 'deadlock',
            accountId: '42',
            displayName: 'New Player',
            avatarUrl: null,
            profile: null,
        );

        self::assertSame($id, $replacedId);
        $account = $this->repo->findByUserIdAndGame('user-1', 'deadlock');
        self::assertSame('42', $account['accountId']);
        self::assertSame('New Player', $account['displayName']);
        self::assertNull($account['profile']);
    }

    public function testBatchOnlyReturnsExistingUsers(): void
    {
        $this->insertUser('user-1');
        $this->insertUser('user-2');

        foreach (['user-1' => '1', 'user-2' => '2', 'deleted-user' => '3'] as $userId => $accountId) {
            $this->repo->upsert($userId, 'deadlock', $accountId, 'Player', null, null);
        }

        $accounts = $this->repo->listByGameAndUserIds('deadlock', ['user-1', 'user-2', 'deleted-user']);

        self::assertCount(2, $accounts);
        self::assertSame(['user-1', 'user-2'], array_column($accounts, 'userId'));
    }

    public function testMarkSyncedAndDelete(): void
    {
        $this->insertUser('user-1');
        $this->repo->upsert('user-1', 'deadlock', '42', 'Player', null, null);

        $this->repo->markSynced('deadlock', ['42']);
        $account = $this->repo->findByUserIdAndGame('user-1', 'deadlock');
        self::assertNotNull($account['lastSyncedAt']);

        self::assertTrue($this->repo->deleteByUserIdAndGame('user-1', 'deadlock'));
        self::assertNull($this->repo->findByUserIdAndGame('user-1', 'deadlock'));
        self::assertFalse($this->repo->deleteByUserIdAndGame('user-1', 'deadlock'));
    }

    private function insertUser(string $id): void
    {
        $stmt = $this->db->prepare('INSERT INTO User (id) VALUES (?)');
        $stmt->execute([$id]);
    }
}
