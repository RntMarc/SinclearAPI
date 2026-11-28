<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Minecraft\MinecraftStatsQuery;

/**
 * Validierung der Query-Parameter des Lese-Endpoints `GET /minecraft/sources`.
 * Ohne Datenbank testbar.
 */
final class MinecraftStatsQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $query = MinecraftStatsQuery::fromQueryParams([]);

        self::assertNull($query->serverId);
        self::assertSame('minecraft:custom', $query->category);
        self::assertSame('minecraft:play_time', $query->stat);
        self::assertNull($query->limit);
        self::assertFalse($query->includeStats);
    }

    public function testServerAndSortParams(): void
    {
        $query = MinecraftStatsQuery::fromQueryParams([
            'serverId' => 'MedionSMP',
            'sortCategory' => 'Minecraft:Mined',
            'sortStat' => 'minecraft:diamond_ore',
            'limit' => '10',
            'includeStats' => 'true',
        ]);

        self::assertSame('MedionSMP', $query->serverId);
        self::assertSame('minecraft:mined', $query->category);
        self::assertSame('minecraft:diamond_ore', $query->stat);
        self::assertSame(10, $query->limit);
        self::assertTrue($query->includeStats);
    }

    public function testEmptyValuesFallBackToDefaults(): void
    {
        $query = MinecraftStatsQuery::fromQueryParams([
            'serverId' => '',
            'sortCategory' => '',
            'sortStat' => '',
            'limit' => '',
        ]);

        self::assertNull($query->serverId);
        self::assertSame('minecraft:custom', $query->category);
        self::assertSame('minecraft:play_time', $query->stat);
        self::assertNull($query->limit);
    }

    public function testMaxLimitIsAccepted(): void
    {
        self::assertSame(500, MinecraftStatsQuery::fromQueryParams(['limit' => '500'])->limit);
    }

    public function testIncludeStatsAcceptsCommonTruthyAndFalsyValues(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE'] as $value) {
            self::assertTrue(MinecraftStatsQuery::fromQueryParams(['includeStats' => $value])->includeStats);
        }

        foreach (['0', 'false', 'no', 'off'] as $value) {
            self::assertFalse(MinecraftStatsQuery::fromQueryParams(['includeStats' => $value])->includeStats);
        }
    }

    public function testInvalidServerIdIsRejected(): void
    {
        $this->assertError('invalid_server_id', ['serverId' => 'bad server']);
        $this->assertError('invalid_server_id', ['serverId' => ['MedionSMP']]);
    }

    public function testInvalidSortCategoryIsRejected(): void
    {
        $this->assertError('invalid_sort_category', ['sortCategory' => 'minecraft']);
        $this->assertError('invalid_sort_category', ['sortCategory' => 'Minecraft custom']);
    }

    public function testInvalidSortStatIsRejected(): void
    {
        $this->assertError('invalid_sort_stat', ['sortStat' => 'play_time']);
        $this->assertError('invalid_sort_stat', ['sortStat' => 'minecraft:play time']);
    }

    public function testInvalidLimitIsRejected(): void
    {
        $this->assertError('invalid_limit', ['limit' => '0']);
        $this->assertError('invalid_limit', ['limit' => '501']);
        $this->assertError('invalid_limit', ['limit' => '-1']);
        $this->assertError('invalid_limit', ['limit' => 'abc']);
        $this->assertError('invalid_limit', ['limit' => ['10']]);
    }

    public function testInvalidIncludeStatsIsRejected(): void
    {
        $this->assertError('invalid_include_stats', ['includeStats' => 'maybe']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertError(string $expected, array $params): void
    {
        try {
            MinecraftStatsQuery::fromQueryParams($params);
        } catch (InvalidArgumentException $e) {
            self::assertSame($expected, $e->getMessage());

            return;
        }

        self::fail('Expected InvalidArgumentException with error ' . $expected);
    }
}
