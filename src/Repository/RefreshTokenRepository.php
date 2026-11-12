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
        ?string $previousTokenHash = null,
        ?DateTimeImmutable $graceUntil = null,
    ): string {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RefreshToken (id, familyId, userId, tokenHash, previousTokenHash, expiresAt, graceUntil, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $id,
            $familyId,
            $userId,
            $tokenHash,
            $previousTokenHash,
            $expiresAt->format('Y-m-d H:i:s'),
            $graceUntil?->format('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /**
     * Findet einen gültigen (nicht widerrufenen, nicht abgelaufenen) Token.
     *
     * Mit $forUpdate wird die Zeile gesperrt (SELECT ... FOR UPDATE), damit
     * zwei parallele /auth/refresh-Requests nicht beide denselben Token als
     * gültig sehen und ihn doppelt rotieren.
     */
    public function findValidToken(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT rt.id, rt.familyId, rt.userId, rt.tokenHash, rt.expiresAt, rt.revokedAt, rt.createdAt,
                       rtf.revokedAt as familyRevokedAt
                FROM RefreshToken rt
                JOIN RefreshTokenFamily rtf ON rtf.id = rt.familyId
                WHERE rt.tokenHash = ? AND rt.revokedAt IS NULL
                  AND rtf.revokedAt IS NULL
                  AND rt.expiresAt > NOW()
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Findet den gültigen Nachfolger eines bereits rotierten Tokens innerhalb
     * der Grace-Periode. Wird verwendet, um ein Replay eines gerade rotierten
     * Tokens zu tolerieren statt die Familie zu sperren.
     */
    public function findGraceEligibleSuccessor(string $previousTokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT rt.id, rt.familyId, rt.userId, rt.tokenHash, rt.expiresAt, rt.revokedAt, rt.createdAt,
                       rtf.revokedAt as familyRevokedAt
                FROM RefreshToken rt
                JOIN RefreshTokenFamily rtf ON rtf.id = rt.familyId
                WHERE rt.previousTokenHash = ? AND rt.revokedAt IS NULL
                  AND rtf.revokedAt IS NULL
                  AND rt.expiresAt > NOW()
                  AND rt.graceUntil > NOW()
                ORDER BY rt.createdAt DESC
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$previousTokenHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Findet einen Token ausschließlich anhand seines Hashes, unabhängig vom
     * Widerrufs-Status. Dient der Reuse-Detection: Ist der Token bekannt,
     * aber nicht mehr gültig, wird die gesamte Familie gesperrt.
     */
    public function findAnyByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, familyId, userId FROM RefreshToken WHERE tokenHash = ? LIMIT 1');
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

    /**
     * Löscht Tokens, deren Gültigkeit bzw. Widerruf älter als $olderThan ist.
     * Hält die Tabelle klein — der Client rotiert den Refresh-Token bei jedem
     * Hintergrund-Poll und würde sonst unbemerkt hunderte Zeilen/Nutzer/Tag
     * erzeugen.
     */
    public function deleteExpiredTokens(DateTimeImmutable $olderThan): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM RefreshToken WHERE expiresAt < ? OR revokedAt < ?'
        );
        $stmt->execute([
            $olderThan->format('Y-m-d H:i:s'),
            $olderThan->format('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount();
    }

    /**
     * Löscht RefreshTokenFamily-Einträge, zu denen keine Tokens mehr existieren
     * (nachdem deren Tokens zuvor aufgeräumt wurden).
     */
    public function deleteOrphanedFamilies(): int
    {
        $stmt = $this->pdo->query(
            'DELETE FROM RefreshTokenFamily
             WHERE NOT EXISTS (
                 SELECT 1 FROM RefreshToken rt WHERE rt.familyId = RefreshTokenFamily.id
             )'
        );
        return $stmt->rowCount();
    }
}
