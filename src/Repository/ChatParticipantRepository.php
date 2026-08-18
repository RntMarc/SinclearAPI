<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ChatParticipantRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Add a participant to a conversation. Idempotent (IGNORE on duplicate).
     */
    public function add(string $conversationId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO ChatParticipant (conversationId, userId, joinedAt, lastReadSeq)
             VALUES (?, ?, NOW(3), 0)'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Find a specific participant record.
     */
    public function find(string $conversationId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ChatParticipant WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$conversationId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get all participants of a conversation.
     */
    public function findByConversation(string $conversationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cp.*, u.displayName, u.image AS userImage
             FROM ChatParticipant cp
             INNER JOIN User u ON u.id = cp.userId
             WHERE cp.conversationId = ?'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the other participant(s) in a direct conversation.
     */
    public function findOtherParticipant(string $conversationId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cp.*, u.displayName, u.image AS userImage
             FROM ChatParticipant cp
             INNER JOIN User u ON u.id = cp.userId
             WHERE cp.conversationId = ? AND cp.userId != ?'
        );
        $stmt->execute([$conversationId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Check if a user is a participant of a conversation.
     */
    public function isParticipant(string $conversationId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ChatParticipant WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Update the lastReadSeq for a participant.
     */
    public function updateLastReadSeq(string $conversationId, string $userId, int $seq): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ChatParticipant SET lastReadSeq = GREATEST(lastReadSeq, ?)
             WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$seq, $conversationId, $userId]);
    }

    /**
     * Update lastSeenAt for a participant.
     */
    public function updateLastSeenAt(string $conversationId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ChatParticipant SET lastSeenAt = NOW(3)
             WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Remove a participant from a conversation.
     */
    public function remove(string $conversationId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatParticipant WHERE conversationId = ? AND userId = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }
}
