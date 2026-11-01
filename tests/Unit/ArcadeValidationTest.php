<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\ArcadeGameService;
use Sinclear\Api\Services\ArcadeScoreService;
use Sinclear\Api\Services\ArcadeSessionService;

final class ArcadeValidationTest extends TestCase
{
    public function testValidSlugs(): void
    {
        self::assertTrue(ArcadeGameService::isValidSlug('snake'));
        self::assertTrue(ArcadeGameService::isValidSlug('2048'));
        self::assertTrue(ArcadeGameService::isValidSlug('my-game-2'));
        self::assertTrue(ArcadeGameService::isValidSlug(str_repeat('a', 50)));
    }

    public function testInvalidSlugs(): void
    {
        self::assertFalse(ArcadeGameService::isValidSlug(''));
        self::assertFalse(ArcadeGameService::isValidSlug('Snake'));
        self::assertFalse(ArcadeGameService::isValidSlug('snake_game'));
        self::assertFalse(ArcadeGameService::isValidSlug('-snake'));
        self::assertFalse(ArcadeGameService::isValidSlug('snake-'));
        self::assertFalse(ArcadeGameService::isValidSlug('snake game'));
        self::assertFalse(ArcadeGameService::isValidSlug('snake/evil'));
        self::assertFalse(ArcadeGameService::isValidSlug(str_repeat('a', 51)));
    }

    public function testTokenHashIsSha256(): void
    {
        $token = 'abc123';

        self::assertSame(hash('sha256', $token), ArcadeSessionService::hashToken($token));
        self::assertSame(64, strlen(ArcadeSessionService::hashToken($token)));
    }

    public function testGeneratedTokensAreLongAndUnique(): void
    {
        $first = ArcadeSessionService::generateToken();
        $second = ArcadeSessionService::generateToken();

        self::assertSame(128, strlen($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testValidateScoreAcceptsIntegers(): void
    {
        self::assertSame(0, ArcadeScoreService::validateScore(0));
        self::assertSame(1234, ArcadeScoreService::validateScore(1234));
        self::assertSame(-42, ArcadeScoreService::validateScore(-42));
    }

    public function testValidateScoreRejectsNonIntegers(): void
    {
        foreach ([null, '1234', 12.5, true, []] as $value) {
            try {
                ArcadeScoreService::validateScore($value);
                self::fail('Expected invalid_score for ' . get_debug_type($value));
            } catch (InvalidArgumentException $e) {
                self::assertSame('invalid_score', $e->getMessage());
            }
        }
    }

    public function testNormalizeMetaAcceptsObjectLikeArrays(): void
    {
        self::assertNull(ArcadeScoreService::normalizeMeta(null));
        self::assertNull(ArcadeScoreService::normalizeMeta([]));

        $meta = ['level' => 7, 'timeMs' => 42310, 'nested' => ['a' => true]];
        self::assertSame($meta, ArcadeScoreService::normalizeMeta($meta));
    }

    public function testNormalizeMetaRejectsListsAndScalars(): void
    {
        foreach ([[1, 2, 3], 'meta', 42, true] as $value) {
            try {
                ArcadeScoreService::normalizeMeta($value);
                self::fail('Expected invalid_meta for ' . get_debug_type($value));
            } catch (InvalidArgumentException $e) {
                self::assertSame('invalid_meta', $e->getMessage());
            }
        }
    }
}
