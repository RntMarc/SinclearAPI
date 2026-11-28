<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\MinecraftOverviewRepository;
use Sinclear\Api\Services\Minecraft\MinecraftOverviewService;
use Sinclear\Api\Services\Minecraft\MinecraftStatsQuery;
use Sinclear\Api\Tests\Support\MinecraftTestSchema;

/**
 * Aggregations-Test der Minecraft-Quellen-Uebersicht gegen MySQL:
 * Summen je Quelle/Server, Spieleranzahl, Ranglisten inkl. Verknuepfung,
 * `serverId`-Filter, `limit` und `includeStats`.
 *
 * Laeuft nur auf dem Server (kein MySQL lokal).
 */
final class MinecraftOverviewServiceTest extends TestCase
{
    private const string P1 = 'f6d584ff-9577-472b-9aa2-88b7878eac37';
    private const string P2 = 'ca48cfb5-bfcb-4f25-bbbf-966cb5892c80';
    private const string P3 = '11111111-2222-3333-4444-555555555555';
    private const string T1 = '2026-11-25 22:56:12.000';
    private const string T2 = '2026-11-25 23:00:00.000';
    private const string T3 = '2026-11-24 10:00:00.000';

    private const string SNAP1 = 'aaaaaaaa-0000-0000-0000-000000000001';
    private const string SNAP2 = 'aaaaaaaa-0000-0000-0000-000000000002';
    private const string SNAP3 = 'aaaaaaaa-0000-0000-0000-000000000003';

    private PDO $db;
    private MinecraftOverviewService $service;

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
        $this->seed();

        $this->service = new MinecraftOverviewService(new MinecraftOverviewRepository($this->db));
    }

    protected function tearDown(): void
    {
        MinecraftTestSchema::drop($this->db);
    }

    public function testGlobalOverviewAggregatesSourcesAndServers(): void
    {
        $result = $this->service->overview(MinecraftStatsQuery::fromQueryParams([]));

        self::assertSame('global', $result['data']['scope']);
        self::assertNull($result['data']['serverId']);
        self::assertSame(
            ['category' => 'minecraft:custom', 'stat' => 'minecraft:play_time'],
            $result['data']['rankedBy'],
        );
        self::assertSame(3, $result['meta']['sourceCount']);
        self::assertSame(2, $result['meta']['serverCount']);

        $source = $this->source($result['data'], 'MedionSMP', 'world');
        self::assertSame('2026-11-25T23:00:00Z', $source['lastCollectedAt']);
        self::assertSame(2, $source['playerCount']);
        self::assertSame(150, ((array) $source['totals'])['minecraft:custom']['minecraft:play_time']);
        self::assertSame(3, ((array) $source['totals'])['minecraft:custom']['minecraft:deaths']);

        self::assertSame(1, $source['ranking'][0]['rank']);
        self::assertSame(self::P1, $source['ranking'][0]['uuid']);
        self::assertSame('Notch', $source['ranking'][0]['name']);
        self::assertSame('user-1', $source['ranking'][0]['linkedUserId']);
        self::assertSame('https://mc-heads.net/avatar/' . self::P1 . '/64', $source['ranking'][0]['avatarUrl']);
        self::assertSame(100, $source['ranking'][0]['value']);
        self::assertArrayNotHasKey('stats', $source['ranking'][0]);

        // Unverknuepfter Spieler bleibt in der Rangliste, ohne Profildaten.
        self::assertSame(2, $source['ranking'][1]['rank']);
        self::assertSame(self::P2, $source['ranking'][1]['uuid']);
        self::assertNull($source['ranking'][1]['linkedUserId']);
        self::assertNull($source['ranking'][1]['avatarUrl']);

        $server = $this->server($result['data'], 'MedionSMP');
        self::assertSame(['nether', 'world'], $server['worlds']);
        self::assertSame('2026-11-25T23:00:00Z', $server['lastCollectedAt']);
        // P1 spielt in beiden Welten, zaehlt aber nur einmal.
        self::assertSame(2, $server['playerCount']);
        self::assertSame(180, ((array) $server['totals'])['minecraft:custom']['minecraft:play_time']);
        self::assertSame(5, ((array) $server['totals'])['minecraft:mined']['minecraft:diamond_ore']);
        self::assertSame(130, $server['ranking'][0]['value']);
        self::assertSame('Notch', $server['ranking'][0]['name']);
        self::assertSame(50, $server['ranking'][1]['value']);
    }

    public function testServerFilterReturnsOnlyTheSelectedServer(): void
    {
        $result = $this->service->overview(
            MinecraftStatsQuery::fromQueryParams(['serverId' => 'MedionSMP']),
        );

        self::assertSame('server', $result['data']['scope']);
        self::assertSame('MedionSMP', $result['data']['serverId']);
        self::assertSame(2, $result['meta']['sourceCount']);
        self::assertSame(1, $result['meta']['serverCount']);
        self::assertSame('MedionSMP', $result['data']['servers'][0]['serverId']);
    }

    public function testUnknownServerReturnsEmptyDataset(): void
    {
        $result = $this->service->overview(
            MinecraftStatsQuery::fromQueryParams(['serverId' => 'Missing']),
        );

        self::assertSame([], $result['data']['sources']);
        self::assertSame([], $result['data']['servers']);
        self::assertSame(0, $result['meta']['sourceCount']);
        self::assertSame(0, $result['meta']['serverCount']);
    }

    public function testLimitTrimsRankingButKeepsPlayerCount(): void
    {
        $result = $this->service->overview(MinecraftStatsQuery::fromQueryParams(['limit' => '1']));

        $source = $this->source($result['data'], 'MedionSMP', 'world');
        self::assertSame(2, $source['playerCount']);
        self::assertCount(1, $source['ranking']);
        self::assertSame('Notch', $source['ranking'][0]['name']);

        $server = $this->server($result['data'], 'MedionSMP');
        self::assertSame(2, $server['playerCount']);
        self::assertCount(1, $server['ranking']);
    }

    public function testRankingUsesSelectedStat(): void
    {
        $result = $this->service->overview(MinecraftStatsQuery::fromQueryParams([
            'sortCategory' => 'minecraft:custom',
            'sortStat' => 'minecraft:deaths',
        ]));

        $source = $this->source($result['data'], 'MedionSMP', 'world');
        self::assertSame('Notch', $source['ranking'][0]['name']);
        self::assertSame(2, $source['ranking'][0]['value']);
        self::assertSame('Alex', $source['ranking'][1]['name']);
        self::assertSame(1, $source['ranking'][1]['value']);

        // Die Nether-Welt hat keine Tode, die Summen bleiben aber vollstaendig.
        $nether = $this->source($result['data'], 'MedionSMP', 'nether');
        self::assertSame([], $nether['ranking']);
        self::assertSame(1, $nether['playerCount']);
        self::assertSame(30, ((array) $nether['totals'])['minecraft:custom']['minecraft:play_time']);
    }

    public function testIncludeStatsAddsPlayerCounters(): void
    {
        $result = $this->service->overview(
            MinecraftStatsQuery::fromQueryParams(['includeStats' => 'true']),
        );

        $source = $this->source($result['data'], 'MedionSMP', 'world');
        self::assertSame(100, ((array) $source['ranking'][0]['stats'])['minecraft:custom']['minecraft:play_time']);
        self::assertSame(2, ((array) $source['ranking'][0]['stats'])['minecraft:custom']['minecraft:deaths']);

        // Server-Rangliste summiert die Einzelwerte ueber alle Welten.
        $server = $this->server($result['data'], 'MedionSMP');
        self::assertSame(130, ((array) $server['ranking'][0]['stats'])['minecraft:custom']['minecraft:play_time']);
        self::assertSame(5, ((array) $server['ranking'][0]['stats'])['minecraft:mined']['minecraft:diamond_ore']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function source(array $data, string $serverId, string $world): array
    {
        foreach ($data['sources'] as $source) {
            if ($source['serverId'] === $serverId && $source['world'] === $world) {
                return $source;
            }
        }

        self::fail(sprintf('Source %s/%s not found', $serverId, $world));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function server(array $data, string $serverId): array
    {
        foreach ($data['servers'] as $server) {
            if ($server['serverId'] === $serverId) {
                return $server;
            }
        }

        self::fail(sprintf('Server %s not found', $serverId));
    }

    private function seed(): void
    {
        $this->db->exec("INSERT INTO MinecraftSource (id, serverId, world) VALUES
            (1, 'MedionSMP', 'world'),
            (2, 'MedionSMP', 'nether'),
            (3, 'OtherSMP', 'world')");

        $player = $this->db->prepare(
            'INSERT INTO MinecraftPlayer (uuid, name, firstSeenAt, lastSeenAt) VALUES (?, ?, ?, ?)'
        );
        $player->execute([self::P1, 'Notch', self::T1, self::T2]);
        $player->execute([self::P2, 'Alex', self::T1, self::T2]);
        $player->execute([self::P3, 'Steve', self::T3, self::T3]);

        $snapshot = $this->db->prepare(
            'INSERT INTO MinecraftSnapshot (id, sourceId, schemaVersion, collectedAt, receivedAt, playerCount)
             VALUES (?, ?, 1, ?, ?, 1)'
        );
        $snapshot->execute([self::SNAP1, 1, self::T2, self::T2]);
        $snapshot->execute([self::SNAP2, 2, self::T1, self::T1]);
        $snapshot->execute([self::SNAP3, 3, self::T3, self::T3]);

        $this->db->exec("INSERT INTO MinecraftStatKey (id, category, stat) VALUES
            (1, 'minecraft:custom', 'minecraft:play_time'),
            (2, 'minecraft:custom', 'minecraft:deaths'),
            (3, 'minecraft:mined', 'minecraft:diamond_ore')");

        $current = $this->db->prepare(
            'INSERT INTO MinecraftStatCurrent
                (sourceId, playerUuid, statKeyId, value, collectedAt, snapshotId)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $current->execute([1, self::P1, 1, 100, self::T2, self::SNAP1]);
        $current->execute([1, self::P1, 2, 2, self::T2, self::SNAP1]);
        $current->execute([1, self::P2, 1, 50, self::T2, self::SNAP1]);
        $current->execute([1, self::P2, 2, 1, self::T2, self::SNAP1]);
        $current->execute([2, self::P1, 1, 30, self::T1, self::SNAP2]);
        $current->execute([2, self::P1, 3, 5, self::T1, self::SNAP2]);
        $current->execute([3, self::P3, 1, 10, self::T3, self::SNAP3]);

        $account = $this->db->prepare(
            'INSERT INTO GameAccount
                (id, userId, game, accountId, displayName, avatarUrl, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $account->execute([
            'ga-1',
            'user-1',
            'minecraft',
            self::P1,
            'Notch',
            'https://mc-heads.net/avatar/' . self::P1 . '/64',
            self::T1,
            self::T1,
        ]);
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
}
