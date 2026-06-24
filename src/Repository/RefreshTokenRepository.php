<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;

final readonly class RefreshTokenRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function createFamily(string $userId): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RefreshTokenFamily (id, userId, createdAt) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$id, $userId]);
        return $id;
    }

    public function createToken(
        string $familyId,
        string $userId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): string {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RefreshToken (id, familyId, userId, tokenHash, expiresAt, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $familyId, $userId, $tokenHash, $expiresAt->format('Y-m-d H:i:s')]);
        return $id;
    }

    public function findValidToken(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.id, rt.familyId, rt.userId, rt.tokenHash, rt.expiresAt, rt.revokedAt, rt.createdAt,
                    rtf.revokedAt as familyRevokedAt
             FROM RefreshToken rt
             JOIN RefreshTokenFamily rtf ON rtf.id = rt.familyId
             WHERE rt.tokenHash = ? AND rt.revokedAt IS NULL
               AND rtf.revokedAt IS NULL
               AND rt.expiresAt > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function revokeToken(string $id, DateTimeImmutable $revokedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE RefreshToken SET revokedAt = ? WHERE id = ?');
        $stmt->execute([$revokedAt->format('Y-m-d H:i:s'), $id]);
    }

    public function revokeFamily(string $familyId, DateTimeImmutable $revokedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE RefreshTokenFamily SET revokedAt = ? WHERE id = ?');
        $stmt->execute([$revokedAt->format('Y-m-d H:i:s'), $familyId]);

        $stmt = $this->pdo->prepare('UPDATE RefreshToken SET revokedAt = ? WHERE familyId = ? AND revokedAt IS NULL');
        $stmt->execute([$revokedAt->format('Y-m-d H:i:s'), $familyId]);
    }

    public function hasActiveTokenInFamily(string $familyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM RefreshToken
             WHERE familyId = ? AND revokedAt IS NULL AND expiresAt > NOW()'
        );
        $stmt->execute([$familyId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function revokeAllForUser(string $userId, DateTimeImmutable $revokedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE RefreshTokenFamily SET revokedAt = ? WHERE userId = ? AND revokedAt IS NULL'
        );
        $stmt->execute([$revokedAt->format('Y-m-d H:i:s'), $userId]);

        $stmt = $this->pdo->prepare(
            'UPDATE RefreshToken rt
             JOIN RefreshTokenFamily rtf ON rtf.id = rt.familyId
             SET rt.revokedAt = ?
             WHERE rtf.userId = ? AND rt.revokedAt IS NULL'
        );
        $stmt->execute([$revokedAt->format('Y-m-d H:i:s'), $userId]);
    }
}
