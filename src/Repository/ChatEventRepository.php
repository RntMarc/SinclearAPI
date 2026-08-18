<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ChatEventRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Create a new chat event. Returns the event seq (global sync cursor).
     */
    public function create(string $conversationId, string $actorId, string $type, ?string $messageId = null): int
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ChatEvent (id, conversationId, actorId, type, messageId, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $conversationId, $actorId, $type, $messageId]);

        $seqStmt = $this->pdo->prepare('SELECT seq FROM ChatEvent WHERE id = ?');
        $seqStmt->execute([$id]);
        return (int) $seqStmt->fetchColumn();
    }

    /**
     * Get all new events (with current message state) for a user since a seq cursor.
     * Used by the sync endpoint.
     *
     * @return array<int, array>
     */
    public function findNewForUser(string $userId, int $afterSeq, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ce.seq AS eventSeq,
                    ce.conversationId,
                    ce.actorId,
                    ce.type,
                    ce.messageId,
                    ce.createdAt AS eventCreatedAt,
                    dm.*,
                    u.displayName AS senderDisplayName,
                    u.image AS senderImage
             FROM ChatEvent ce
             INNER JOIN ChatParticipant cp ON cp.conversationId = ce.conversationId AND cp.userId = ?
             LEFT JOIN DirectMessage dm ON dm.id = ce.messageId
             LEFT JOIN User u ON u.id = dm.senderId
             WHERE ce.seq > ?
             ORDER BY ce.seq ASC
             LIMIT ?'
        );
        $stmt->execute([$userId, $afterSeq, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count events older than a given date.
     */
    public function countOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ChatEvent WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete events older than a given date in batches.
     * Returns the number of deleted rows in this batch.
     */
    public function deleteOlderThanBatch(int $days, int $batchSize = 1000): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatEvent WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?'
        );
        $stmt->execute([$days, $batchSize]);
        return $stmt->rowCount();
    }
}
