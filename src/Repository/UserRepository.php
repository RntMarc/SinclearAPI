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
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, discordAvatarHash, createdAt FROM User WHERE email = ?');
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByDiscordId(string $discordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, discordAvatarHash, createdAt FROM User WHERE discordId = ?');
        $stmt->execute([$discordId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, displayName, discordId, isAdmin, image, discordAvatarHash, createdAt, birthday FROM User WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, email, displayName, discordId, isAdmin, image, discordAvatarHash, createdAt, birthday FROM User');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    public function findAllIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM User');
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
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

    /** @return list<string> */
    public function findAdminIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM User WHERE isAdmin = 1');
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    /**
     * Alle Nutzer mit hinterlegtem Geburtstag inkl. Sichtbarkeit und
     * Close-Friend-Status gegenüber dem anfragenden Nutzer.
     *
     * @return list<array<string, mixed>>
     */
    public function findBirthdayCandidates(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image, u.birthday,
                    COALESCE(p.birthdayVisibility, 1) AS birthdayVisibility,
                    (cf.friendId IS NOT NULL) AS isCloseFriend
             FROM User u
             LEFT JOIN UserPreferences p ON p.userId = u.id
             LEFT JOIN CloseFriend cf ON cf.userId = u.id AND cf.friendId = ?
             WHERE u.birthday IS NOT NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM User');
        return (int) $stmt->fetchColumn();
    }

    public function create(string $email, string $displayName, string $discordId, ?string $discordAvatarHash = null): array
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO User (id, email, passwordHash, displayName, discordId, discordAvatarHash, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $email, '', $displayName, $discordId, $discordAvatarHash]);

        return $this->findById($id);
    }
}
