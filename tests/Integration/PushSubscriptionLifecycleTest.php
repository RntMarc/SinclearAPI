<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Services\Cron\Tasks\CleanupStalePushSubscriptionsTask;

class PushSubscriptionLifecycleTest extends TestCase
{
    private PDO $db;
    private PushSubscriptionRepository $repo;

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

        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS User");

        $this->db->exec("
            CREATE TABLE User (
                id varchar(191) NOT NULL PRIMARY KEY,
                email varchar(191) NOT NULL UNIQUE,
                passwordHash varchar(191) NOT NULL,
                displayName varchar(191) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
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
                KEY idx_pushsub_failures (consecutiveFailures)
            )
        ");

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-1', 'a@test.com', 'hash', 'Alice')");

        $this->repo = new PushSubscriptionRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS PushSubscription");
        $this->db->exec("DROP TABLE IF EXISTS User");
    }

    private function insert(string $endpoint): string
    {
        return $this->repo->upsert([
            'userId' => 'user-1',
            'type' => 'unifiedpush',
            'endpoint' => $endpoint,
        ]);
    }

    private function get(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM PushSubscription WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    private function age(string $id, string $column, int $days): void
    {
        $allowed = ['lastSeenAt', 'lastSuccessAt'];
        $this->assertContains($column, $allowed);
        $stmt = $this->db->prepare(
            "UPDATE PushSubscription SET $column = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?"
        );
        $stmt->execute([$days, $id]);
    }

    private function runTask(): void
    {
        $container = new class($this->db) implements ContainerInterface {
            public function __construct(private PDO $pdo) {}
            public function get(string $id): mixed
            {
                return $id === PDO::class ? $this->pdo : new NullLogger();
            }
            public function has(string $id): bool
            {
                return $id === PDO::class || is_subclass_of($id, LoggerInterface::class);
            }
        };

        (new CleanupStalePushSubscriptionsTask())->execute($container, new NullLogger());
    }

    public function testUpsertRefreshesLastSeenAndResetsFailures(): void
    {
        $id = $this->insert('https://ntfy.example/topic-a');
        $this->repo->markFailure($id, 'HTTP 503: boom');
        $this->repo->markFailure($id, 'HTTP 503: boom');
        $this->age($id, 'lastSeenAt', 30);

        $row = $this->get($id);
        $this->assertSame(2, (int) $row['consecutiveFailures']);
        $this->assertNotNull($row['lastError']);

        // Re-registration (App came back) heals the subscription
        $id2 = $this->repo->upsert([
            'userId' => 'user-1',
            'type' => 'unifiedpush',
            'endpoint' => 'https://ntfy.example/topic-a',
        ]);
        $this->assertSame($id, $id2);

        $row = $this->get($id);
        $this->assertSame(0, (int) $row['consecutiveFailures']);
        $this->assertNull($row['lastError']);
        $this->assertNull($row['lastErrorAt']);
        // lastSeenAt is fresh again (same day)
        $this->assertGreaterThan(date('Y-m-d') . ' 00:00:00', $row['lastSeenAt']);
    }

    public function testMarkSuccessResetsFailures(): void
    {
        $id = $this->insert('https://ntfy.example/topic-b');
        $this->repo->markFailure($id, 'timeout');
        $this->repo->markFailure($id, 'timeout');

        $this->repo->markSuccess($id);

        $row = $this->get($id);
        $this->assertSame(0, (int) $row['consecutiveFailures']);
        $this->assertNull($row['lastError']);
        $this->assertNotNull($row['lastSuccessAt']);
    }

    public function testCronTaskDeletesOnlyAfterFailureThreshold(): void
    {
        $dying = $this->insert('https://ntfy.example/dead');
        $surviving = $this->insert('https://ntfy.example/alive');

        for ($i = 0; $i < 9; $i++) {
            $this->repo->markFailure($surviving, 'flaky');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->repo->markFailure($dying, 'persistent error');
        }

        $this->runTask();

        $this->assertSame([], $this->get($dying), '10 consecutive failures must be swept');
        $this->assertNotSame([], $this->get($surviving), '9 failures must survive');
    }

    public function testCronTaskDeletesStaleWithoutRecentSuccess(): void
    {
        $stale = $this->insert('https://ntfy.example/stale');
        $this->age($stale, 'lastSeenAt', 91);

        $this->runTask();

        $this->assertSame([], $this->get($stale));
    }

    public function testCronTaskKeepsFreshlyRegisteredSubscription(): void
    {
        $fresh = $this->insert('https://ntfy.example/fresh');

        $this->runTask();

        $this->assertNotSame([], $this->get($fresh), 'Fresh lastSeenAt must survive even without any success');
    }

    public function testCronTaskKeepsStaleSeenButRecentlySuccessful(): void
    {
        $active = $this->insert('https://ntfy.example/active');
        $this->age($active, 'lastSeenAt', 91);
        $this->repo->markSuccess($active);
        $this->age($active, 'lastSeenAt', 91);

        $this->runTask();

        $this->assertNotSame([], $this->get($active), 'Recent successful delivery must protect from the stale sweep');
    }

    public function testCronTaskDeletesStaleSeenAndStaleSuccess(): void
    {
        $dead = $this->insert('https://ntfy.example/dead-long');
        $this->repo->markSuccess($dead);
        $this->age($dead, 'lastSeenAt', 91);
        $this->age($dead, 'lastSuccessAt', 91);

        $this->runTask();

        $this->assertSame([], $this->get($dead));
    }
}
