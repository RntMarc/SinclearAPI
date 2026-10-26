<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\Games\Deadlock\DeadlockClient;
use Sinclear\Api\Services\Games\Deadlock\DeadlockIntegration;
use Sinclear\Api\Services\Games\GameStatsCache;

final class DeadlockIntegrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/deadlock-integration-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testRefreshAllWritesNormalizedStatsAndMeta(): void
    {
        $matches = [];
        for ($i = 0; $i < 25; $i++) {
            $matches[] = [
                'account_id' => 42,
                'match_id' => 1000 + $i,
                'hero_id' => 7,
                'hero_level' => 10,
                'start_time' => 100 + $i,
                'game_mode' => 1,
                'match_mode' => 1,
                'player_team' => 0,
                'player_kills' => 1,
                'player_deaths' => 2,
                'player_assists' => 3,
                'net_worth' => 1000,
                'last_hits' => 10,
                'denies' => 1,
                'match_duration_s' => 1800,
                'match_result' => 1,
                'player_match_outcome' => 1,
                'abandoned_time_s' => 0,
            ];
        }

        $client = $this->client([
            new Response(200, [], json_encode([
                [
                    'id' => 7,
                    'name' => 'Wraith',
                    'images' => [
                        'icon_image_small' => 'https://example.test/wraith_sm.png',
                        'icon_hero_card' => 'https://example.test/wraith_card.png',
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                [
                    'tier' => 1,
                    'name' => 'Initiate',
                    'images' => ['large' => 'https://example.test/rank01_lg.png'],
                ],
            ])),
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'badge' => 12,
                'rank' => 1,
                'subrank' => 2,
                'last_match' => ['match_id' => 999, 'start_time' => 90],
            ]])),
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'hero_id' => 7,
                'matches_played' => 4,
                'wins' => 3,
                'last_played' => 200,
                'time_played' => 3600,
                'kills' => 5,
                'deaths' => 6,
                'assists' => 7,
            ]])),
            new Response(200, [], json_encode($matches)),
        ]);

        $cache = new GameStatsCache($this->dir, 3600);
        $integration = new DeadlockIntegration($this->deadlockClient($client), $cache, new NullLogger());

        $integration->refreshAll([['accountId' => '42']]);

        $stats = $cache->read('deadlock', '42');
        self::assertNotNull($stats);
        self::assertSame('42', $stats['accountId']);
        self::assertSame(12, $stats['rank']['badge']);
        self::assertSame('Initiate', $stats['rank']['name']);
        self::assertSame('https://example.test/rank01_lg.png', $stats['rank']['iconUrl']);
        self::assertSame('999', $stats['rank']['lastMatch']['matchId']);
        self::assertSame(4, $stats['heroStats'][0]['matchesPlayed']);
        self::assertSame('Wraith', $stats['heroStats'][0]['heroName']);
        self::assertSame('https://example.test/wraith_sm.png', $stats['heroStats'][0]['heroIconUrl']);
        self::assertSame(3, $stats['heroStats'][0]['wins']);
        self::assertSame(1, $stats['heroStats'][0]['losses']);
        self::assertSame(0.75, $stats['heroStats'][0]['winRate']);
        self::assertCount(20, $stats['matchHistory']);
        self::assertSame('1024', $stats['matchHistory'][0]['matchId']);
        self::assertSame('Wraith', $stats['matchHistory'][0]['heroName']);
        self::assertSame('https://example.test/wraith_sm.png', $stats['matchHistory'][0]['heroIconUrl']);

        self::assertNotNull($cache->readAux('deadlock', 'heroes', 86400));
        self::assertNotNull($cache->readAux('deadlock', 'ranks', 86400));

        $meta = $cache->meta('deadlock');
        self::assertNotNull($meta['lastRefreshedAt']);
        self::assertNotNull($meta['nextRefreshAt']);
        self::assertSame(3600, $meta['intervalSeconds']);
    }

    public function testRefreshAllUsesCachedAssetsWithoutRequestingThemAgain(): void
    {
        $cache = new GameStatsCache($this->dir, 3600);
        $cache->writeAux('deadlock', 'heroes', [7 => ['name' => 'Wraith', 'iconUrl' => 'hero-icon']]);
        $cache->writeAux('deadlock', 'ranks', [1 => ['name' => 'Initiate', 'iconUrl' => 'rank-icon']]);

        $client = $this->client([
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'badge' => 12,
                'rank' => 1,
                'subrank' => 2,
            ]])),
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'hero_id' => 7,
                'matches_played' => 1,
                'wins' => 1,
            ]])),
            new Response(200, [], json_encode([[
                'match_id' => 1,
                'hero_id' => 7,
                'start_time' => 10,
            ]])),
        ]);

        $integration = new DeadlockIntegration($this->deadlockClient($client), $cache, new NullLogger());
        $integration->refreshAll([['accountId' => '42']]);

        $stats = $cache->read('deadlock', '42');
        self::assertNotNull($stats);
        self::assertSame('Initiate', $stats['rank']['name']);
        self::assertSame('rank-icon', $stats['rank']['iconUrl']);
        self::assertSame('Wraith', $stats['heroStats'][0]['heroName']);
        self::assertSame('hero-icon', $stats['heroStats'][0]['heroIconUrl']);
    }

    public function testRefreshAllWritesStatsWithNullAssetFieldsWhenAssetsFail(): void
    {
        $client = $this->client([
            new Response(500, [], ''),
            new Response(500, [], ''),
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'badge' => 12,
                'rank' => 1,
                'subrank' => 2,
            ]])),
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'hero_id' => 7,
                'matches_played' => 2,
                'wins' => 1,
            ]])),
            new Response(200, [], json_encode([[
                'match_id' => 1,
                'hero_id' => 7,
                'start_time' => 10,
            ]])),
        ]);

        $cache = new GameStatsCache($this->dir, 3600);
        $integration = new DeadlockIntegration($this->deadlockClient($client), $cache, new NullLogger());

        $integration->refreshAll([['accountId' => '42']]);

        $stats = $cache->read('deadlock', '42');
        self::assertNotNull($stats);
        self::assertNull($stats['rank']['name']);
        self::assertNull($stats['rank']['iconUrl']);
        self::assertNull($stats['heroStats'][0]['heroName']);
        self::assertNull($stats['heroStats'][0]['heroIconUrl']);
        self::assertNull($stats['matchHistory'][0]['heroName']);
        self::assertNull($stats['matchHistory'][0]['heroIconUrl']);
        self::assertNull($cache->readAux('deadlock', 'heroes', 86400));
        self::assertNull($cache->readAux('deadlock', 'ranks', 86400));
    }

    public function testResolveProfileDerivesSteamId64(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode([[
                'account_id' => 42,
                'personaname' => 'Player',
                'avatarfull' => 'https://example.test/avatar_full.jpg',
                'avatar' => 'https://example.test/avatar.jpg',
                'profileurl' => 'https://steamcommunity.test/player',
            ]])),
        ]);

        $integration = new DeadlockIntegration(
            $this->deadlockClient($client),
            new GameStatsCache($this->dir, 3600),
            new NullLogger(),
        );

        $profile = $integration->resolveProfile('42');

        self::assertSame('Player', $profile['displayName']);
        self::assertSame('https://example.test/avatar_full.jpg', $profile['avatarUrl']);
        self::assertSame('76561197960265770', $profile['profile']['steamId64']);
    }

    public function testResolveProfileMapsServiceUnavailableToApiUnavailable(): void
    {
        $client = $this->client([new Response(503, ['Retry-After' => '30'], '')]);

        $integration = new DeadlockIntegration(
            $this->deadlockClient($client),
            new GameStatsCache($this->dir, 3600),
            new NullLogger(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('game_api_unavailable');

        $integration->resolveProfile('42');
    }

    /** @param list<Response> $responses */
    private function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    private function deadlockClient(Client $client): DeadlockClient
    {
        return new DeadlockClient(new Settings(
            app: [],
            db: [],
            jwt: [],
            discord: [],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
            games: [
                'deadlock_base_url' => 'https://deadlock.test',
                'deadlock_api_key' => '',
                'cache_ttl' => 3600,
            ],
        ), new NullLogger(), $client);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
