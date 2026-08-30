<?php

namespace Sinclear\Api\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\OtpTokenRepository;

/**
 * Verifies that OTP lookups are strictly scoped by their flow type.
 *
 * Regression guard for the authentication bypass where an email OTP token
 * (type `email_otp`) could be redeemed without the target email address.
 * DB-dependent; runs on the server (see AGENTS.md).
 */
final class OtpTokenRepositoryTest extends TestCase
{
    private PDO $db;
    private OtpTokenRepository $repo;

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

        $this->db->exec("DROP TABLE IF EXISTS OtpToken");
        $this->db->exec("
            CREATE TABLE OtpToken (
                id varchar(191) NOT NULL PRIMARY KEY,
                email varchar(191) NOT NULL,
                code varchar(6) NOT NULL,
                type varchar(20) NOT NULL DEFAULT 'email_otp',
                expiresAt datetime(3) NOT NULL,
                usedAt datetime(3) DEFAULT NULL,
                createdAt datetime(3) NOT NULL
            )
        ");

        $this->repo = new OtpTokenRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS OtpToken");
    }

    private function createToken(string $type, string $code, string $email, DateTimeImmutable $expiresAt): string
    {
        return $this->repo->create($email, $code, $expiresAt, $type);
    }

    public function testEmailOtpCannotBeRedeemedAsDiscordPairing(): void
    {
        $expiresAt = new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'));
        $this->createToken(
            OtpTokenRepository::TYPE_EMAIL_OTP,
            '123456',
            'victim@example.com',
            $expiresAt,
        );

        $this->assertNotNull(
            $this->repo->findValidEmailOtp('victim@example.com', '123456'),
            'email OTP must be found with the correct email address',
        );
        $this->assertNull(
            $this->repo->findValidEmailOtp('attacker@example.com', '123456'),
            'email OTP must not be found with a different email address',
        );
        $this->assertNull(
            $this->repo->findValidDiscordPairing('123456'),
            'email OTP must never be redeemable as a Discord pairing code',
        );
    }

    public function testDiscordPairingCannotBeRedeemedAsEmailOtp(): void
    {
        $expiresAt = new DateTimeImmutable('+2 minutes', new DateTimeZone('UTC'));
        $this->createToken(
            OtpTokenRepository::TYPE_DISCORD_PAIRING,
            '654321',
            'user@example.com',
            $expiresAt,
        );

        $this->assertNotNull(
            $this->repo->findValidDiscordPairing('654321'),
            'pairing code must be found without an email address',
        );
        $this->assertNull(
            $this->repo->findValidEmailOtp('user@example.com', '654321'),
            'pairing code must not be redeemable as an email OTP',
        );
    }

    public function testDiscordRelinkOnlyResolvesViaRelinkLookup(): void
    {
        $metadata = json_encode([
            'type' => 'discord_relink',
            'userId' => 'user-123',
            'newDiscordId' => 'discord-456',
        ]);
        $expiresAt = new DateTimeImmutable('+2 minutes', new DateTimeZone('UTC'));
        $this->createToken(
            OtpTokenRepository::TYPE_DISCORD_RELINK,
            '111222',
            $metadata,
            $expiresAt,
        );

        $token = $this->repo->findValidDiscordRelink('111222');
        $this->assertNotNull($token, 'relink code must resolve via findValidDiscordRelink');
        $this->assertSame($metadata, $token['email']);

        $this->assertNull(
            $this->repo->findValidDiscordPairing('111222'),
            'relink code must not resolve as a plain Discord pairing code',
        );
    }

    public function testUsedTokenCannotBeRedeemedAgain(): void
    {
        $expiresAt = new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'));
        $id = $this->createToken(
            OtpTokenRepository::TYPE_EMAIL_OTP,
            '777888',
            'user@example.com',
            $expiresAt,
        );

        $this->assertNotNull($this->repo->findValidEmailOtp('user@example.com', '777888'));
        $this->repo->markUsed($id);
        $this->assertNull($this->repo->findValidEmailOtp('user@example.com', '777888'));
    }

    public function testExpiredTokenCannotBeRedeemed(): void
    {
        $expiredAt = new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC'));
        $this->createToken(
            OtpTokenRepository::TYPE_EMAIL_OTP,
            '999000',
            'user@example.com',
            $expiredAt,
        );

        $this->assertNull($this->repo->findValidEmailOtp('user@example.com', '999000'));
    }
}