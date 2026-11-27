<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\MinecraftStatRepository;
use Sinclear\Api\Tests\Support\MinecraftTestSchema;

/**
 * Fokussierter Test der nicht-trivialen SQL-Pfade in MinecraftStatRepository:
 * get-or-create-Idempotenz fuer Source und StatKey sowie die "letzter Wert vor
 * Zeitpunkt T"-Abfrage fuer Nachzuegler.
 */
final class MinecraftStatRepositoryTest extends TestCase
{
    private PDO $db;
    private MinecraftStatRepository $repo;

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

        $this->repo = new MinecraftStatRepository($this->db);
    }

    protected function tearDown(): void
    {
        MinecraftTestSchema::drop($this->db);
    }

    public function testGetOrCreateSourceIsIdempotent(): void
    {
        $first = $this->repo->getOrCreateSource('MedionSMP', 'world');
        $second = $this->repo->getOrCreateSource('MedionSMP', 'world');
        $other = $this->repo->getOrCreateSource('MedionSMP', 'nether');

        self::assertSame($first, $second);
        self::assertNotSame($first, $other);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM MinecraftSource')->fetchColumn());
    }

    public function testGetOrCreateStatKeysIsIdempotent(): void
    {
        $keys = [
            ['category' => 'minecraft:custom', 'stat' => 'minecraft:play_time'],
            ['category' => 'minecraft:custom', 'stat' => 'minecraft:walk_one_cm'],
        ];

        $first = $this->repo->getOrCreateStatKeys($keys);
        $second = $this->repo->getOrCreateStatKeys($keys);

        self::assertCount(2, $first);
        self::assertSame($first, $second);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM MinecraftStatKey')->fetchColumn());
    }

    public function testGetHistoryBeforeReturnsLastValueBeforeTimestamp(): void
    {
        $sourceId = $this->repo->getOrCreateSource('MedionSMP', 'world');
        $playerUuid = 'f6d584ff-9577-472b-9aa2-88b7878eac37';

        $this->db->prepare('INSERT INTO MinecraftPlayer (uuid, name, firstSeenAt, lastSeenAt) VALUES (?, NULL, ?, ?)')
            ->execute([$playerUuid, '2026-11-25 22:56:12.000', '2026-11-25 23:00:00.000']);

        $keyId = $this->repo->getOrCreateStatKeys([
            ['category' => 'minecraft:custom', 'stat' => 'minecraft:play_time'],
        ])["minecraft:custom\0minecraft:play_time"];

        $this->repo->insertHistoryMany($sourceId, $playerUuid, '2026-11-25 22:56:12.000', 'snap-1', [
            ['statKeyId' => $keyId, 'value' => 100],
        ]);
        $this->repo->insertHistoryMany($sourceId, $playerUuid, '2026-11-25 23:00:00.000', 'snap-2', [
            ['statKeyId' => $keyId, 'value' => 150],
        ]);

        // Vor 22:57 ist der letzte Wert 100.
        self::assertSame(
            [ $keyId => 100 ],
            $this->repo->getHistoryBefore($sourceId, $playerUuid, '2026-11-25 22:57:00.000'),
        );

        // Vor 23:01 ist der letzte Wert 150.
        self::assertSame(
            [ $keyId => 150 ],
            $this->repo->getHistoryBefore($sourceId, $playerUuid, '2026-11-25 23:01:00.000'),
        );

        // Vor der ersten Messung existiert kein Wert.
        self::assertSame([], $this->repo->getHistoryBefore($sourceId, $playerUuid, '2026-11-25 22:00:00.000'));
    }
}
