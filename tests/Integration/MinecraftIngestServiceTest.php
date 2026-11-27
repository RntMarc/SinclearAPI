<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Repository\GameAccountRepository;
use Sinclear\Api\Repository\MinecraftPlayerRepository;
use Sinclear\Api\Repository\MinecraftSnapshotRepository;
use Sinclear\Api\Repository\MinecraftStatRepository;
use Sinclear\Api\Services\Minecraft\MinecraftIngestService;
use Sinclear\Api\Tests\Support\MinecraftTestSchema;

/**
 * End-to-End-Test des Minecraft-Ingest-Service gegen MySQL:
 * Idempotenz, Diff gegen den aktuellen Stand, Nachzuegler, Reset auf 0,
 * fehlende Stats und Spieler-Name/Zeitstempel.
 *
 * Laeuft nur auf dem Server (kein MySQL lokal).
 */
final class MinecraftIngestServiceTest extends TestCase
{
    private PDO $db;
    private MinecraftIngestService $service;

    private const string UUID = 'f6d584ff-9577-472b-9aa2-88b7878eac37';
    private const string T1 = '2026-11-25 22:56:12.000';
    private const string T2 = '2026-11-25 23:00:00.000';
    private const string T_LATE = '2026-11-25 22:57:00.000';

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

        MinecraftTestSchema::drop($this->db);
        MinecraftTestSchema::create($this->db);
        $this->createGameAccountTable();

        $this->service = new MinecraftIngestService(
            $this->db,
            new MinecraftSnapshotRepository($this->db),
            new MinecraftPlayerRepository($this->db),
            new MinecraftStatRepository($this->db),
            new GameAccountRepository($this->db),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        MinecraftTestSchema::drop($this->db);
    }

    public function testFirstSnapshotStoresCurrentAndHistory(): void
    {
        $result = $this->service->ingest($this->snapshot(
            snapshotId: '866a12cc-4ea1-4e2b-96d1-c87094707c67',
            collectedAt: self::T1,
            players: [$this->player(stats: [
                ['minecraft:custom', 'minecraft:play_time', 100],
                ['minecraft:custom', 'minecraft:walk_one_cm', 50],
            ])],
        ));

        self::assertFalse($result['duplicate']);
        self::assertSame(1, $result['accepted']);
        self::assertSame(2, $result['changedStats']);

        self::assertSame(2, $this->rowCount('MinecraftStatCurrent'));
        self::assertSame(2, $this->rowCount('MinecraftStatHistory'));
        self::assertSame(1, $this->rowCount('MinecraftSnapshot'));
        self::assertSame(1, $this->rowCount('MinecraftPlayer'));
        self::assertSame(1, $this->rowCount('MinecraftSnapshotPlayer'));

        self::assertSame(100, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
        self::assertSame(50, $this->currentValue('minecraft:custom', 'minecraft:walk_one_cm'));
    }

    public function testDuplicateSnapshotIsIgnored(): void
    {
        $snapshotId = '866a12cc-4ea1-4e2b-96d1-c87094707c67';

        $this->service->ingest($this->snapshot($snapshotId, self::T1, [$this->player()]));
        $result = $this->service->ingest($this->snapshot($snapshotId, self::T1, [$this->player()]));

        self::assertTrue($result['duplicate']);
        self::assertSame(0, $result['accepted']);
        self::assertSame(0, $result['changedStats']);

        self::assertSame(1, $this->rowCount('MinecraftSnapshot'));
        self::assertSame(2, $this->rowCount('MinecraftStatHistory'));
    }

    public function testUnchangedStatsDoNotAddHistory(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
            ['minecraft:custom', 'minecraft:walk_one_cm', 50],
        ])]));

        $result = $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 150],
            ['minecraft:custom', 'minecraft:walk_one_cm', 50],
        ])]));

        self::assertSame(1, $result['changedStats']);
        self::assertSame(3, $this->rowCount('MinecraftStatHistory'));
        self::assertSame(150, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
        self::assertSame(50, $this->currentValue('minecraft:custom', 'minecraft:walk_one_cm'));
    }

    public function testLatecomerDoesNotChangeCurrent(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 150],
        ])]));

        // Nachzuegler mit Wert 120 zwischen T1 und T2: landet in der History,
        // veraendert den aktuellen Stand (150) aber nicht.
        $result = $this->service->ingest($this->snapshot('snap-late', self::T_LATE, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 120],
        ])]));

        self::assertSame(1, $result['changedStats']);
        self::assertSame(150, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
        self::assertSame(3, $this->rowCount('MinecraftStatHistory'));
    }

    public function testLatecomerUnchangedValueDoesNotAddHistory(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 150],
        ])]));

        // Nachzuegler mit Wert 100 (= letzter Wert vor T_LATE) -> keine Aenderung.
        $result = $this->service->ingest($this->snapshot('snap-late', self::T_LATE, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        self::assertSame(0, $result['changedStats']);
        self::assertSame(2, $this->rowCount('MinecraftStatHistory'));
        self::assertSame(150, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
    }

    public function testResetToZero(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 0],
        ])]));

        self::assertSame(0, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
        self::assertSame(2, $this->rowCount('MinecraftStatHistory'));
    }

    public function testMissingStatsRemainUnchanged(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
            ['minecraft:custom', 'minecraft:walk_one_cm', 50],
        ])]));

        // Zweiter Snapshot enthaelt walk_one_cm nicht mehr -> bleibt unveraendert.
        $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 150],
        ])]));

        self::assertSame(50, $this->currentValue('minecraft:custom', 'minecraft:walk_one_cm'));
        self::assertSame(150, $this->currentValue('minecraft:custom', 'minecraft:play_time'));
    }

    public function testPlayerNameAndTimestamps(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(name: 'Old', stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        $this->service->ingest($this->snapshot('snap-2', self::T2, [$this->player(name: 'New', stats: [
            ['minecraft:custom', 'minecraft:play_time', 150],
        ])]));

        // Aelterer Nachzuegler darf den neueren Namen nicht ueberschreiben.
        $this->service->ingest($this->snapshot('snap-late', self::T_LATE, [$this->player(name: 'Ancient', stats: [
            ['minecraft:custom', 'minecraft:play_time', 120],
        ])]));

        $stmt = $this->db->query('SELECT name, firstSeenAt, lastSeenAt FROM MinecraftPlayer WHERE uuid = ' . $this->db->quote(self::UUID));
        $row = $stmt->fetch();

        self::assertSame('New', $row['name']);
        self::assertSame(self::T1, $row['firstSeenAt']);
        self::assertSame(self::T2, $row['lastSeenAt']);
    }

    public function testMultipleSourcesAreSeparated(): void
    {
        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 100],
        ])]));

        // Gleiche UUID, andere Welt -> eigene Quelle, eigener Stand.
        $payload = $this->snapshot('snap-2', self::T2, [$this->player(stats: [
            ['minecraft:custom', 'minecraft:play_time', 200],
        ])]);
        $payload['world'] = 'nether';
        $this->service->ingest($payload);

        self::assertSame(2, $this->rowCount('MinecraftSource'));
        self::assertSame(2, $this->rowCount('MinecraftStatCurrent'));

        $stmt = $this->db->query(
            'SELECT c.value FROM MinecraftStatCurrent c
             JOIN MinecraftSource s ON s.id = c.sourceId
             WHERE s.world = \'world\' AND c.playerUuid = ' . $this->db->quote(self::UUID)
        );
        self::assertSame(100, (int) $stmt->fetchColumn());
    }

    public function testIngestUpdatesLinkedAccountLastSyncedAt(): void
    {
        // Verknuepften Minecraft-Account anlegen.
        $this->db->prepare(
            'INSERT INTO GameAccount (id, userId, game, accountId, displayName, avatarUrl, profile, lastSyncedAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NOW(3), NOW(3))'
        )->execute(['ga-1', 'user-1', 'minecraft', self::UUID, 'Notch', null]);

        $this->service->ingest($this->snapshot('snap-1', self::T1, [$this->player()]));

        $stmt = $this->db->prepare('SELECT lastSyncedAt FROM GameAccount WHERE id = ?');
        $stmt->execute(['ga-1']);
        self::assertSame(self::T1, $stmt->fetchColumn());
    }

    public function testIngestDoesNotMoveLinkedAccountLastSyncedAtBackwards(): void
    {
        $this->db->prepare(
            'INSERT INTO GameAccount (id, userId, game, accountId, displayName, avatarUrl, profile, lastSyncedAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NOW(3), NOW(3))'
        )->execute(['ga-1', 'user-1', 'minecraft', self::UUID, 'Notch', null, self::T2]);

        // Nachzuegler mit aelterem collectedAt darf lastSyncedAt nicht zuruecksetzen.
        $this->service->ingest($this->snapshot('snap-late', self::T_LATE, [$this->player()]));

        $stmt = $this->db->prepare('SELECT lastSyncedAt FROM GameAccount WHERE id = ?');
        $stmt->execute(['ga-1']);
        self::assertSame(self::T2, $stmt->fetchColumn());
    }

    private function createGameAccountTable(): void
    {
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
                UNIQUE KEY GameAccount_game_accountId_unique (game, accountId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @param list<array{uuid: string, name: string|null, dataVersion: int|null, stats: list<array{category: string, stat: string, value: int}>}> $players
     * @return array<string, mixed>
     */
    private function snapshot(string $snapshotId, string $collectedAt, array $players): array
    {
        return [
            'schemaVersion' => 1,
            'serverId' => 'MedionSMP',
            'world' => 'world',
            'snapshotId' => $snapshotId,
            'collectedAt' => $collectedAt,
            'players' => $players,
        ];
    }

    /**
     * @param list<array{category: string, stat: string, value: int}> $stats
     * @return array{uuid: string, name: string|null, dataVersion: int|null, stats: list<array{category: string, stat: string, value: int}>}
     */
    private function player(string $name = 'Notch', array $stats = []): array
    {
        if ($stats === []) {
            $stats = [
                ['minecraft:custom', 'minecraft:play_time', 100],
                ['minecraft:custom', 'minecraft:walk_one_cm', 50],
            ];
        }

        return [
            'uuid' => self::UUID,
            'name' => $name,
            'dataVersion' => 5023,
            'stats' => array_map(
                static fn (array $s): array => ['category' => $s[0], 'stat' => $s[1], 'value' => $s[2]],
                $stats,
            ),
        ];
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    private function currentValue(string $category, string $stat): int
    {
        $stmt = $this->db->prepare(
            'SELECT c.value
             FROM MinecraftStatCurrent c
             JOIN MinecraftStatKey k ON k.id = c.statKeyId
             WHERE k.category = ? AND k.stat = ? AND c.playerUuid = ?'
        );
        $stmt->execute([$category, $stat, self::UUID]);

        return (int) $stmt->fetchColumn();
    }
}
