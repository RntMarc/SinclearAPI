<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class StoryRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM Story s
             LEFT JOIN User u ON u.id = s.userId
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Story (id, userId, image, caption, createdAt, expiresAt)
             VALUES (?, ?, ?, ?, NOW(3), DATE_ADD(NOW(3), INTERVAL 7 DAY))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['image'],
            $data['caption'] ?? null,
        ]);
        return $id;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM Story WHERE id = ?')->execute([$id]);
    }

    /**
     * Returns all active stories (expiresAt in the future), newest first,
     * enriched with the author's display name and avatar.
     */
    public function findActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.id, s.userId, s.image, s.caption, s.createdAt, s.expiresAt,
                    u.displayName, u.image AS userImage
             FROM Story s
             LEFT JOIN User u ON u.id = s.userId
             WHERE s.expiresAt > NOW(3)
             ORDER BY s.userId, s.createdAt DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markViewed(string $storyId, string $userId): void
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO StoryView (id, storyId, userId, viewedAt)
             VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $storyId, $userId]);
    }

    /**
     * Returns the IDs of the given stories that the user has already viewed.
     *
     * @param string[] $storyIds
     * @return string[]
     */
    public function findViewedStoryIds(string $userId, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT storyId FROM StoryView WHERE userId = ? AND storyId IN ($placeholders)"
        );
        $stmt->execute([$userId, ...$storyIds]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'storyId');
    }

    public function countViews(string $storyId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM StoryView WHERE storyId = ?');
        $stmt->execute([$storyId]);
        return (int) $stmt->fetchColumn();
    }
}
