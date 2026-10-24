<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\ExternalDataCacheRepository;

/**
 * Regressionstest fuer den fehlenden 'brightsky'-Wert im
 * ExternalDataCache.source-ENUM: Wetterwarnungen fuer Standorte ohne
 * InfraNode-Slug werden ueber BrightSky geladen und muessen cachebar sein,
 * sonst antwortete /external-data/weather/warnings mit HTTP 500.
 *
 * DB-abhaengig; laeuft auf dem Server (siehe AGENTS.md). Das Schema hier
 * entspricht der Migration 20261024000000_add_brightsky_cache_source.sql.
 */
final class ExternalDataCacheRepositoryTest extends TestCase
{
    private PDO $db;
    private ExternalDataCacheRepository $repo;

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

        $this->db->exec('DROP TABLE IF EXISTS ExternalDataCache');
        $this->db->exec("
            CREATE TABLE ExternalDataCache (
                id varchar(191) NOT NULL,
                data_type varchar(64) NOT NULL,
                location_key varchar(191) NOT NULL,
                source enum('infranode','open-meteo','mixed','unsplash','brightsky') NOT NULL,
                payload json NOT NULL,
                expires_at datetime(3) NOT NULL,
                created_at datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                UNIQUE KEY uk_cache_type_location (data_type, location_key)
            )
        ");

        $this->repo = new ExternalDataCacheRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS ExternalDataCache');
    }

    public function testBrightSkyWarningsCanBeCachedAndRead(): void
    {
        $payload = [
            'warnings' => [[
                'event' => 'WINDBÖEN',
                'level' => 1,
                'headline' => 'Amtliche WARNUNG vor WINDBÖEN',
                'start' => '2026-10-24 02:00:00',
                'end' => '2026-10-24 14:00:00',
            ]],
            'special_warnings' => [],
            'max_level' => 1,
            'count' => 1,
            '_attribution' => [
                'text' => 'Datenbasis: Deutscher Wetterdienst via Bright Sky',
                'url' => 'https://brightsky.dev',
            ],
        ];

        $this->repo->save('weather_warnings', '52.5200,13.4050', 'brightsky', $payload, 300);

        $cached = $this->repo->find('weather_warnings', '52.5200,13.4050');

        self::assertNotNull($cached);
        self::assertSame('brightsky', $cached['source']);
        self::assertSame('weather_warnings', $cached['data_type']);
        // MySQL speichert JSON normalisiert und ordnet Objekt-Keys neu, daher
        // assertEquals statt assertSame (Key-Reihenfolge ist irrelevant).
        self::assertEquals($payload, $cached['payload']);
    }

    public function testSavingTwiceOverwritesSourceAndPayload(): void
    {
        $this->repo->save('weather_warnings', 'berlin', 'infranode', ['warnings' => [], 'count' => 0], 300);
        $this->repo->save('weather_warnings', 'berlin', 'brightsky', ['warnings' => [], 'count' => 0, 'max_level' => 0], 60);

        $cached = $this->repo->find('weather_warnings', 'berlin');

        self::assertNotNull($cached);
        self::assertSame('brightsky', $cached['source']);
        self::assertSame(0, $cached['payload']['max_level']);
    }
}
