<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class OtpTokenRepository
{
    public const TYPE_EMAIL_OTP = 'email_otp';
    public const TYPE_DISCORD_PAIRING = 'discord_pairing';
    public const TYPE_DISCORD_RELINK = 'discord_relink';
    public const TYPE_ADMIN_STEPUP = 'admin_stepup';

    /**
     * Maximale Fehlversuche pro ausgestelltem Code, bevor dieser ungültig wird.
     */
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(
        string $email,
        string $code,
        DateTimeImmutable $expiresAt,
        string $type = self::TYPE_EMAIL_OTP,
        ?string $sessionId = null,
    ): string {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO OtpToken (id, email, code, type, sessionId, expiresAt, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $utcExpiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        $stmt->execute([$id, $email, $code, $type, $sessionId, $utcExpiresAt->format('Y-m-d H:i:s.v')]);
        return $id;
    }

    public function findValidEmailOtp(string $email, string $code): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_EMAIL_OTP, $email);
    }

    public function findValidDiscordPairing(string $code, ?string $sessionId = null): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_DISCORD_PAIRING, sessionId: $sessionId);
    }

    public function findValidDiscordRelink(string $code, ?string $sessionId = null): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_DISCORD_RELINK, sessionId: $sessionId);
    }

    public function findValidAdminStepup(string $email, string $code): ?array
    {
        return $this->findValidByCodeAndType($code, self::TYPE_ADMIN_STEPUP, $email);
    }

    private function findValidByCodeAndType(
        string $code,
        string $type,
        ?string $email = null,
        ?string $sessionId = null,
    ): ?array {
        $conditions = 'type = ? AND code = ? AND usedAt IS NULL AND expiresAt > NOW() AND attempts < ?';
        $params = [$type, $code, self::MAX_ATTEMPTS];

        if ($email !== null) {
            $conditions .= ' AND email = ?';
            $params[] = $email;
        }

        if ($sessionId !== null) {
            $conditions .= ' AND sessionId = ?';
            $params[] = $sessionId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, email, code, type, expiresAt, usedAt, attempts, createdAt
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

    /**
     * Registriert einen Fehlversuch für den zuletzt ausgestellten, noch aktiven
     * Code einer E-Mail + eines Typs. Ab MAX_ATTEMPTS wird der Code ungültig
     * (Brute-Force-Schutz).
     */
    public function incrementAttemptsForEmail(string $email, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE OtpToken SET attempts = attempts + 1
             WHERE email = ? AND type = ? AND usedAt IS NULL AND expiresAt > NOW()
             ORDER BY createdAt DESC
             LIMIT 1'
        );
        $stmt->execute([$email, $type]);
    }

    /**
     * Registriert einen Fehlversuch für den zuletzt ausgestellten, noch aktiven
     * Code einer Session + eines Typs (Discord-Pairing).
     */
    public function incrementAttemptsForSession(string $sessionId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE OtpToken SET attempts = attempts + 1
             WHERE sessionId = ? AND type = ? AND usedAt IS NULL AND expiresAt > NOW()
             ORDER BY createdAt DESC
             LIMIT 1'
        );
        $stmt->execute([$sessionId, $type]);
    }

    public function countRecentByEmail(string $email, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM OtpToken WHERE email = ? AND createdAt > ?'
        );
        $utcSince = $since->setTimezone(new DateTimeZone('UTC'));
        $stmt->execute([$email, $utcSince->format('Y-m-d H:i:s.v')]);
        return (int) $stmt->fetchColumn();
    }
}
