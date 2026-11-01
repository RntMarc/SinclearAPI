<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ArcadeSessionRepository
{
    private const string COLUMNS = 'id, userId, gameId, tokenHash, expiresAt, revokedAt, createdAt';

    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(
        string $userId,
        string $gameId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): string {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO ArcadeSession (id, userId, gameId, tokenHash, expiresAt, revokedAt, createdAt)
             VALUES (?, ?, ?, ?, ?, NULL, NOW(3))'
        );
        $stmt->execute([
            $id,
            $userId,
            $gameId,
            $tokenHash,
            $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeSession
             WHERE tokenHash = ? AND revokedAt IS NULL AND expiresAt > NOW(3)
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function revoke(string $id, DateTimeImmutable $revokedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ArcadeSession SET revokedAt = ? WHERE id = ? AND revokedAt IS NULL'
        );
        $stmt->execute([$revokedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $id]);
    }

    public function revokeAllForUserAndGame(string $userId, string $gameId, DateTimeImmutable $revokedAt): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ArcadeSession SET revokedAt = ?
             WHERE userId = ? AND gameId = ? AND revokedAt IS NULL'
        );
        $stmt->execute([$revokedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $userId, $gameId]);

        return $stmt->rowCount();
    }

    public function deleteExpiredOrRevoked(): int
    {
        $deleted = $this->pdo->exec(
            'DELETE FROM ArcadeSession WHERE expiresAt < NOW(3) OR revokedAt IS NOT NULL'
        );

        return $deleted === false ? 0 : $deleted;
    }

    public function deleteByGameId(string $gameId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ArcadeSession WHERE gameId = ?');
        $stmt->execute([$gameId]);

        return $stmt->rowCount();
    }
}
