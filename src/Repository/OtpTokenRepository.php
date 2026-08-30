<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;

final readonly class OtpTokenRepository
{
    public const TYPE_EMAIL_OTP = 'email_otp';
    public const TYPE_DISCORD_PAIRING = 'discord_pairing';
    public const TYPE_DISCORD_RELINK = 'discord_relink';

    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(string $email, string $code, DateTimeImmutable $expiresAt, string $type = self::TYPE_EMAIL_OTP): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO OtpToken (id, email, code, type, expiresAt, createdAt) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $email, $code, $type, $expiresAt->format('Y-m-d H:i:s.v')]);
        return $id;
    }

    public function findValidEmailOtp(string $email, string $code): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_EMAIL_OTP, $email);
    }

    public function findValidDiscordPairing(string $code): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_DISCORD_PAIRING);
    }

    public function findValidDiscordRelink(string $code): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_DISCORD_RELINK);
    }

    private function findValidByCodeAndType(string $code, string $type, ?string $email = null): ?array
    {
        $conditions = 'type = ? AND code = ? AND usedAt IS NULL AND expiresAt > NOW()';
        $params = [$type, $code];

        if ($email !== null) {
            $conditions .= ' AND email = ?';
            $params[] = $email;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, email, code, type, expiresAt, usedAt, createdAt
             FROM OtpToken
             WHERE $conditions
             ORDER BY createdAt DESC
             LIMIT 1"
        );
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function markUsed(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE OtpToken SET usedAt = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countRecentByEmail(string $email, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM OtpToken WHERE email = ? AND createdAt > ?'
        );
        $stmt->execute([$email, $since->format('Y-m-d H:i:s.v')]);
        return (int) $stmt->fetchColumn();
    }
}
