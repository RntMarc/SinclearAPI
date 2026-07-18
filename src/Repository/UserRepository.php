<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class UserRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, createdAt FROM User WHERE email = ?');
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByDiscordId(string $discordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, createdAt FROM User WHERE discordId = ?');
        $stmt->execute([$discordId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, createdAt, birthday, birthdayVisibility, emailVisibility, onboardingCompleted FROM User WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, email, displayName, discordId, isAdmin, image, createdAt, birthday, birthdayVisibility, emailVisibility, onboardingCompleted FROM User');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT id, email, displayName, image
             FROM User
             WHERE displayName LIKE ? OR email LIKE ?
             ORDER BY displayName
             LIMIT 20'
        );
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM User');
        return (int) $stmt->fetchColumn();
    }

    public function create(string $email, string $displayName, string $discordId): array
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO User (id, email, passwordHash, displayName, discordId, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $email, '', $displayName, $discordId]);

        return $this->findById($id);
    }
}
