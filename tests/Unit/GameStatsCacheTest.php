<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Games\GameStatsCache;

final class GameStatsCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/game-stats-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testWriteAndReadStats(): void
    {
        $cache = new GameStatsCache($this->dir, 3600);
        $cache->write('deadlock', '179673173', ['accountId' => '179673173', 'rank' => ['badge' => 12]]);

        self::assertSame(
            ['accountId' => '179673173', 'rank' => ['badge' => 12]],
            $cache->read('deadlock', '179673173'),
        );
    }

    public function testReadReturnsNullWhenMissing(): void
    {
        $cache = new GameStatsCache($this->dir, 3600);

        self::assertNull($cache->read('deadlock', '42'));
    }

    public function testReadReturnsNullWhenExpired(): void
    {
        $cache = new GameStatsCache($this->dir, 60);
        $cache->write('deadlock', '42', ['accountId' => '42']);

        touch($this->dir . '/deadlock/42.json', time() - 120);

        self::assertNull($cache->read('deadlock', '42'));
    }

    public function testMetaDefaultsWhenFileMissing(): void
    {
        $cache = new GameStatsCache($this->dir, 1800);

        self::assertSame([
            'lastRefreshedAt' => null,
            'nextRefreshAt' => null,
            'intervalSeconds' => 1800,
        ], $cache->meta('deadlock'));
    }

    public function testWriteMetaIsReadBack(): void
    {
        $cache = new GameStatsCache($this->dir, 3600);
        $cache->writeMeta('deadlock', '2026-10-25T14:00:00Z', '2026-10-25T15:00:00Z');

        self::assertSame([
            'lastRefreshedAt' => '2026-10-25T14:00:00Z',
            'nextRefreshAt' => '2026-10-25T15:00:00Z',
            'intervalSeconds' => 3600,
        ], $cache->meta('deadlock'));
    }

    public function testCleanRemovesOldStatsButKeepsMeta(): void
    {
        $cache = new GameStatsCache($this->dir, 3600);
        $cache->write('deadlock', '1', ['accountId' => '1']);
        $cache->writeMeta('deadlock', '2026-10-25T14:00:00Z', '2026-10-25T15:00:00Z');

        $statsFile = $this->dir . '/deadlock/1.json';
        touch($statsFile, time() - 7200);

        self::assertSame(1, $cache->clean('deadlock'));
        self::assertNull($cache->read('deadlock', '1'));
        self::assertNotNull($cache->meta('deadlock')['lastRefreshedAt']);
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
