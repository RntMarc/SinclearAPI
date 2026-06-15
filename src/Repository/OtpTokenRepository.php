<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;

final readonly class OtpTokenRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(string $email, string $code, DateTimeImmutable $expiresAt): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO OtpToken (id, email, code, expiresAt, createdAt) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $email, $code, $expiresAt->format('Y-m-d H:i:s.v')]);
        return $id;
    }

    public function findValid(string $email, string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, code, expiresAt, usedAt, createdAt
             FROM OtpToken
             WHERE email = ? AND code = ? AND usedAt IS NULL AND expiresAt > NOW()
             ORDER BY createdAt DESC
             LIMIT 1'
        );
        $stmt->execute([$email, $code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findValidByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, code, expiresAt, usedAt, createdAt
             FROM OtpToken
             WHERE code = ? AND usedAt IS NULL AND expiresAt > NOW()
             ORDER BY createdAt DESC
             LIMIT 1'
        );
        $stmt->execute([$code]);
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
