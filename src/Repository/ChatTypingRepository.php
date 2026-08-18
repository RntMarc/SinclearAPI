<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class ChatTypingRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Set typing indicator for a user in a conversation (expires in 5 seconds).
     */
    public function touch(string $conversationId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ChatTyping (conversationId, userId, expiresAt)
             VALUES (?, ?, DATE_ADD(NOW(3), INTERVAL 5 SECOND))
             ON DUPLICATE KEY UPDATE expiresAt = DATE_ADD(NOW(3), INTERVAL 5 SECOND)'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Clear typing indicator for a user in a conversation.
     */
    public function clear(string $conversationId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatTyping WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Get all user IDs currently typing in a conversation (non-expired).
     *
     * @return string[]
     */
    public function findTypingUserIds(string $conversationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT userId FROM ChatTyping WHERE conversationId = ? AND expiresAt > NOW(3)'
        );
        $stmt->execute([$conversationId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'userId');
    }

    /**
     * Get typing state for all conversations of a user (for sync endpoint).
     *
     * @return array<string, string[]> conversationId => [userId, ...]
     */
    public function findTypingForUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ct.conversationId, ct.userId
             FROM ChatTyping ct
             INNER JOIN ChatParticipant cp ON cp.conversationId = ct.conversationId AND cp.userId = ?
             WHERE ct.expiresAt > NOW(3) AND ct.userId != ?'
        );
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['conversationId']][] = $row['userId'];
        }
        return $result;
    }

    /**
     * Cleanup expired typing indicators.
     */
    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatTyping WHERE expiresAt < NOW(3)'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
