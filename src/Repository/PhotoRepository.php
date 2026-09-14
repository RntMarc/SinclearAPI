<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

/**
 * Read-only lookup of users who linked an Unsplash account, together with
 * the visibility level of that link. Used by the photo feed; the actual
 * per-requester visibility decision lives in UserPolicy.
 */
final readonly class PhotoRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * All users with a non-empty Unsplash handle.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWithUnsplash(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id, u.displayName, u.image AS avatar,
                    s.unsplashHandle,
                    COALESCE(p.unsplashVisibility, 1) AS unsplashVisibility
             FROM User u
             JOIN SocialInfo s ON s.userId = u.id
             LEFT JOIN UserPreferences p ON p.userId = u.id
             WHERE s.unsplashHandle IS NOT NULL AND LENGTH(s.unsplashHandle) > 0'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A single user with a linked Unsplash handle, or null if the user does
     * not exist or has no handle.
     *
     * @return array<string, mixed>|null
     */
    public function findUserWithUnsplash(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.displayName, u.image AS avatar,
                    s.unsplashHandle,
                    COALESCE(p.unsplashVisibility, 1) AS unsplashVisibility
             FROM User u
             JOIN SocialInfo s ON s.userId = u.id
             LEFT JOIN UserPreferences p ON p.userId = u.id
             WHERE u.id = ? AND s.unsplashHandle IS NOT NULL AND LENGTH(s.unsplashHandle) > 0'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
