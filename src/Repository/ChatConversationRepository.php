<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ChatConversationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ChatConversation WHERE id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find an existing direct conversation between two users.
     */
    public function findDirectConversation(string $userIdA, string $userIdB): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cc.*
             FROM ChatConversation cc
             INNER JOIN ChatParticipant cp1 ON cp1.conversationId = cc.id AND cp1.userId = ?
             INNER JOIN ChatParticipant cp2 ON cp2.conversationId = cc.id AND cp2.userId = ?
             WHERE cc.type = \'direct\''
        );
        $stmt->execute([$userIdA, $userIdB]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $type = 'direct', ?string $name = null): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ChatConversation (id, type, name, createdAt, updatedAt)
             VALUES (?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([$id, $type, $name]);
        return $id;
    }

    public function updateTimestamp(string $conversationId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ChatConversation SET updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$conversationId]);
    }

    /**
     * Update the image of a group conversation.
     */
    public function updateImage(string $conversationId, ?string $image): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ChatConversation SET image = ?, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$image, $conversationId]);
    }

    /**
     * List conversations for a user, with last message preview and unread count.
     * Supports both direct and group conversations.
     */
    public function listForUser(string $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cc.*,
                    cp.lastReadSeq,
                    cp.lastSeenAt,
                    CASE WHEN cc.type = \'direct\' THEN cp2.lastReadSeq ELSE NULL END AS otherLastReadSeq,
                    CASE WHEN cc.type = \'direct\' THEN u.id ELSE NULL END AS otherUserId,
                    CASE WHEN cc.type = \'direct\' THEN u.displayName ELSE NULL END AS otherUserDisplayName,
                    CASE WHEN cc.type = \'direct\' THEN u.image ELSE NULL END AS otherUserImage,
                    dm.content AS lastMessageContent,
                    dm.senderId AS lastMessageSenderId,
                    dm.createdAt AS lastMessageCreatedAt,
                    dm.deletedAt AS lastMessageDeletedAt,
                    (SELECT COUNT(*) FROM DirectMessage dm2
                     WHERE dm2.conversationId = cc.id
                       AND dm2.seq > cp.lastReadSeq
                       AND dm2.senderId != ?
                       AND dm2.deletedAt IS NULL) AS unreadCount
             FROM ChatConversation cc
             INNER JOIN ChatParticipant cp ON cp.conversationId = cc.id AND cp.userId = ?
             LEFT JOIN ChatParticipant cp2 ON cp2.conversationId = cc.id
               AND cp2.userId != ? AND cc.type = \'direct\'
             LEFT JOIN User u ON u.id = cp2.userId AND cc.type = \'direct\'
             LEFT JOIN DirectMessage dm ON dm.conversationId = cc.id
               AND dm.seq = (SELECT MAX(dm3.seq) FROM DirectMessage dm3 WHERE dm3.conversationId = cc.id)
             ORDER BY cc.updatedAt DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $userId, $userId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count participants in a conversation (for group member count).
     */
    public function countParticipants(string $conversationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ChatParticipant WHERE conversationId = ?'
        );
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Count conversations for a user.
     */
    public function countForUser(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM ChatConversation cc
             INNER JOIN ChatParticipant cp ON cp.conversationId = cc.id AND cp.userId = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete conversations that have no messages and are older than a threshold.
     */
    public function deleteOrphaned(int $olderThanDays = 1): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatConversation
             WHERE id NOT IN (SELECT DISTINCT conversationId FROM DirectMessage)
               AND createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$olderThanDays]);
        return $stmt->rowCount();
    }
}
