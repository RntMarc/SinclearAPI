<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Games\GameRegistry;
use Sinclear\Api\Services\Games\GameStatsIntegrationInterface;

final class GameRegistryTest extends TestCase
{
    public function testReturnsRegisteredIntegration(): void
    {
        $integration = new FakeGameStatsIntegration();
        $registry = new GameRegistry(['deadlock' => $integration]);

        self::assertTrue($registry->has('deadlock'));
        self::assertFalse($registry->has('unknown'));
        self::assertSame($integration, $registry->get('deadlock'));
        self::assertSame(['deadlock'], $registry->slugs());
    }

    public function testUnknownGameThrows(): void
    {
        $registry = new GameRegistry([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_game');

        $registry->get('unknown');
    }
}

final class FakeGameStatsIntegration implements GameStatsIntegrationInterface
{
    public function refreshAll(array $accounts): void
    {
    }

    public function getCachedStats(string $accountId): ?array
    {
        return null;
    }

    public function getCacheMeta(): array
    {
        return [
            'lastRefreshedAt' => null,
            'nextRefreshAt' => null,
            'intervalSeconds' => 3600,
        ];
    }

    public function resolveProfile(string $accountId): array
    {
        return [
            'displayName' => 'Fake',
            'avatarUrl' => null,
            'profile' => [],
        ];
    }
}
