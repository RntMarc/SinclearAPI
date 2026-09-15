<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\MatrixSyncService;

class MatrixSyncServiceTest extends TestCase
{
    public function testBackoffStartsAtFiveMinutes(): void
    {
        self::assertSame(300, MatrixSyncService::calculateBackoffSeconds(0));
    }

    public function testBackoffDoublesExponentially(): void
    {
        self::assertSame(600, MatrixSyncService::calculateBackoffSeconds(1));
        self::assertSame(1200, MatrixSyncService::calculateBackoffSeconds(2));
        self::assertSame(2400, MatrixSyncService::calculateBackoffSeconds(3));
    }

    public function testBackoffIsCappedAtSixtyMinutes(): void
    {
        self::assertSame(3600, MatrixSyncService::calculateBackoffSeconds(4));
        self::assertSame(3600, MatrixSyncService::calculateBackoffSeconds(10));
        self::assertSame(3600, MatrixSyncService::calculateBackoffSeconds(100));
    }

    public function testBackoffClampsNegativeInputToZero(): void
    {
        self::assertSame(300, MatrixSyncService::calculateBackoffSeconds(-5));
    }
}
