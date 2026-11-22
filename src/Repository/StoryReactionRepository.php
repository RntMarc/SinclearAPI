<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Data access for story reactions.
 *
 * A user has exactly one reaction per story: (storyId, userId) is unique, so
 * adding a different emoji replaces the previous one.
 */
final readonly class StoryReactionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Set (add or replace) the reaction of a user on a story.
     */
    public function set(string $storyId, string $userId, string $emoji): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO StoryReaction (id, storyId, userId, emoji, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE emoji = ?, createdAt = NOW(3)'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $storyId, $userId, $emoji, $emoji]);
    }

    /**
     * Remove the reaction of a user. Idempotent: removing a missing reaction
     * is a no-op.
     */
    public function remove(string $storyId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM StoryReaction WHERE storyId = ? AND userId = ?'
        );
        $stmt->execute([$storyId, $userId]);
    }

    /**
     * All reactions of a story, newest first.
     *
     * @return array<int, array{storyId: string, emoji: string, userId: string, createdAt: string, displayName: string|null, image: string|null}>
     */
    public function findByStoryId(string $storyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.storyId, r.emoji, r.userId, r.createdAt, u.displayName, u.image
             FROM StoryReaction r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.storyId = ?
             ORDER BY r.createdAt DESC'
        );
        $stmt->execute([$storyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
