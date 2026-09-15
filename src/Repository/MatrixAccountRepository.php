<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

final readonly class MatrixAccountRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT userId, localpart, matrixUserId, passwordEncrypted, displayNameSynced, createdAt, updatedAt
             FROM MatrixAccount WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $userId, string $localpart, string $passwordEncrypted): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO MatrixAccount (userId, localpart, passwordEncrypted)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $localpart, $passwordEncrypted]);
    }

    public function setMatrixUserId(string $userId, string $matrixUserId): void
    {
        $stmt = $this->pdo->prepare('UPDATE MatrixAccount SET matrixUserId = ? WHERE userId = ?');
        $stmt->execute([$matrixUserId, $userId]);
    }

    public function setDisplayNameSynced(string $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare('UPDATE MatrixAccount SET displayNameSynced = ? WHERE userId = ?');
        $stmt->execute([$displayName, $userId]);
    }

    /** @return list<string> */
    public function findUserIdsMissingAccountOrMatrixId(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id AS userId
             FROM User u
             LEFT JOIN MatrixAccount m ON m.userId = u.id
             WHERE m.userId IS NULL OR m.matrixUserId IS NULL'
        );
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'userId');
    }

    /**
     * Drift-Erkennung: aktive Accounts, deren Anzeigename in Matrix abweicht.
     *
     * @return list<array{userId: string, displayName: string}>
     */
    public function findDisplayNameDrift(): array
    {
        $stmt = $this->pdo->query(
            'SELECT m.userId, u.displayName
             FROM MatrixAccount m
             JOIN User u ON u.id = m.userId
             WHERE m.matrixUserId IS NOT NULL
               AND (m.displayNameSynced IS NULL OR m.displayNameSynced <> u.displayName)'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDisplayName(string $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT displayName FROM User WHERE id = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result === false ? null : (string) $result;
    }

    /**
     * Alle Accounts inkl. aktuellem Anzeigenamen (Admin-Dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllWithUser(): array
    {
        $stmt = $this->pdo->query(
            'SELECT m.userId, m.localpart, m.matrixUserId, m.displayNameSynced, m.createdAt, m.updatedAt,
                    u.displayName, u.email
             FROM MatrixAccount m
             JOIN User u ON u.id = m.userId
             ORDER BY m.createdAt ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM MatrixAccount')->fetchColumn();
    }
}
