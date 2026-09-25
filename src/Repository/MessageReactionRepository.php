<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Data access for message reactions.
 *
 * A reaction is identified by (messageId, userId, emoji); adding an existing
 * reaction is a no-op (idempotent), removing a missing one is a no-op.
 */
final readonly class MessageReactionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Add a reaction. Idempotent: an existing (message, user, emoji) tuple
     * keeps its original id/createdAt.
     */
    public function add(string $messageId, string $userId, string $emoji): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO MessageReaction (id, messageId, userId, emoji, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([Uuid::uuid7()->toString(), $messageId, $userId, $emoji]);
    }

    /**
     * Remove a reaction. Idempotent: removing a missing reaction is a no-op.
     */
    public function remove(string $messageId, string $userId, string $emoji): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM MessageReaction
             WHERE messageId = ? AND userId = ? AND emoji = ?'
        );
        $stmt->execute([$messageId, $userId, $emoji]);
    }

    /**
     * All reactions of a single message, oldest first.
     *
     * @return array<int, array{messageId: string, emoji: string, userId: string, displayName: string|null, image: string|null}>
     */
    public function findByMessageId(string $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.messageId, r.emoji, r.userId, u.displayName, u.image
             FROM MessageReaction r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.messageId = ?
             ORDER BY r.createdAt ASC'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Batch-load reactions for many messages, grouped by messageId.
     *
     * @param array<int, string> $messageIds
     * @return array<string, array<int, array{messageId: string, emoji: string, userId: string, displayName: string|null, image: string|null}>>
     */
    public function findByMessageIds(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter($messageIds, static fn ($id) => $id !== '')));
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT r.messageId, r.emoji, r.userId, u.displayName, u.image
             FROM MessageReaction r
             LEFT JOIN User u ON u.id = r.userId
             WHERE r.messageId IN ($placeholders)
             ORDER BY r.createdAt ASC"
        );
        $stmt->execute($messageIds);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[$row['messageId']][] = $row;
        }
        return $grouped;
    }

    /**
     * Remove all reactions of a message (used when a message is soft-deleted).
     */
    public function deleteByMessageId(string $messageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM MessageReaction WHERE messageId = ?');
        $stmt->execute([$messageId]);
    }
}
