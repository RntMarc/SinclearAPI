<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Services\Auth\TokenService;

final class TokenServiceTest extends TestCase
{
    private PDO $pdo;
    private TokenService $service;

    protected function setUp(): void
    {
        $this->pdo = $this->createStub(PDO::class);

        $settings = new Settings(
            app: [],
            db: [],
            jwt: [
                'issuer' => 'test',
                'access_ttl' => 3600,
                'refresh_ttl' => 86400,
                'refresh_reuse_grace' => 60,
                'private_key' => 'test',
                'public_key' => 'test',
            ],
            discord: [],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
        );

        $this->service = new TokenService(
            $settings,
            $this->pdo,
            new RefreshTokenRepository($this->pdo),
            new JtiBlacklistRepository($this->pdo),
        );
    }

    /** @param mixed $fetchResult */
    private function stmt(mixed $fetchResult): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchResult);
        return $stmt;
    }

    private function validToken(string $id, string $familyId, string $userId, string $tokenHash): array
    {
        return [
            'id' => $id,
            'familyId' => $familyId,
            'userId' => $userId,
            'tokenHash' => $tokenHash,
            'expiresAt' => '2099-01-01 00:00:00',
            'revokedAt' => null,
            'createdAt' => '2026-01-01 00:00:00',
            'familyRevokedAt' => null,
        ];
    }

    public function testNormalRotationReturnsNewToken(): void
    {
        $oldHash = hash('sha256', 'old');
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $this->stmt($this->validToken('t1', 'f1', 'u1', $oldHash)), // findValidToken
            $this->stmt(null), // revokeToken (UPDATE)
            $this->stmt(null), // createToken (INSERT)
        );

        $result = $this->service->rotateRefreshToken($oldHash);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['refresh_token']);
        $this->assertSame('u1', $result['user_id']);
        $this->assertIsInt($result['expires_at']);
    }

    public function testGraceReplayWithinWindowReRotatesWithoutRevokingFamily(): void
    {
        $oldHash = hash('sha256', 'old');
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // findValidToken -> null (bereits rotiert)
            $this->stmt($this->validToken('t2', 'f1', 'u1', hash('sha256', 'new'))), // findGraceEligibleSuccessor
            $this->stmt(null), // revokeToken
            $this->stmt(null), // createToken
        );

        $result = $this->service->rotateRefreshToken($oldHash);

        $this->assertNotNull($result);
        $this->assertSame('u1', $result['user_id']);
    }

    public function testReuseAfterGraceRevokesFamily(): void
    {
        $oldHash = hash('sha256', 'old');
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $this->stmt(false), // findValidToken -> null
            $this->stmt(false), // findGraceEligibleSuccessor -> null (Grace abgelaufen)
            $this->stmt(['id' => 't1', 'familyId' => 'f1', 'userId' => 'u1']), // findAnyByHash
            $this->stmt(null), // revokeFamily (RefreshTokenFamily UPDATE)
            $this->stmt(null), // revokeFamily (RefreshToken UPDATE)
        );

        $result = $this->service->rotateRefreshToken($oldHash);

        $this->assertNull($result);
    }
}
