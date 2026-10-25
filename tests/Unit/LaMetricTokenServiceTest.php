<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\LaMetricTokenRepository;
use Sinclear\Api\Services\LaMetricTokenService;

final class LaMetricTokenServiceTest extends TestCase
{
    private function createRepositoryDouble(): LaMetricTokenRepository
    {
        $pdo = $this->createMock(PDO::class);
        return new LaMetricTokenRepository($pdo);
    }

    public function testValidateTokenReturnsNullOnInvalidFormat(): void
    {
        $repo = $this->createRepositoryDouble();
        $service = new LaMetricTokenService($repo);

        $this->assertNull($service->validateToken('invalid_token'));
        $this->assertNull($service->validateToken(''));
    }

    public function testValidateTokenReturnsNullWhenTokenNotFound(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $validToken = str_repeat('a', 64);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([$validToken]);

        $stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $repo = new LaMetricTokenRepository($pdo);
        $service = new LaMetricTokenService($repo);

        $this->assertNull($service->validateToken($validToken));
    }

    public function testValidateTokenReturnsUserIdWhenValid(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmtSelect = $this->createMock(PDOStatement::class);
        $stmtUpdate = $this->createMock(PDOStatement::class);

        $validToken = str_repeat('a', 64);
        $future = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($stmtSelect, $stmtUpdate) {
                if (str_contains($sql, 'SELECT')) {
                    return $stmtSelect;
                }
                return $stmtUpdate;
            });

        $stmtSelect->expects($this->once())
            ->method('execute')
            ->with([$validToken]);

        $stmtSelect->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'id' => 'token-uuid-1',
                'userId' => 'user-uuid-1',
                'token' => $validToken,
                'expiresAt' => $future,
                'lastUsedAt' => null,
                'createdAt' => '2026-01-01 00:00:00',
            ]);

        $stmtUpdate->expects($this->once())
            ->method('execute');

        $repo = new LaMetricTokenRepository($pdo);
        $service = new LaMetricTokenService($repo);

        $this->assertSame('user-uuid-1', $service->validateToken($validToken));
    }

    public function testValidateTokenReturnsNullWhenExpired(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $validToken = str_repeat('a', 64);
        $past = (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([$validToken]);

        $stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'id' => 'token-uuid-1',
                'userId' => 'user-uuid-1',
                'token' => $validToken,
                'expiresAt' => $past,
                'lastUsedAt' => null,
                'createdAt' => '2026-01-01 00:00:00',
            ]);

        $repo = new LaMetricTokenRepository($pdo);
        $service = new LaMetricTokenService($repo);

        $this->assertNull($service->validateToken($validToken));
    }
}
