<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DirectMessageRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dm.*, u.displayName AS senderDisplayName, u.image AS senderImage
             FROM DirectMessage dm
             LEFT JOIN User u ON u.id = dm.senderId
             WHERE dm.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new direct message. Returns the created record.
     *
     * @return array{id: string, seq: int, createdAt: string}
     */
    public function create(array $data): array
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DirectMessage (id, conversationId, senderId, type, content, payload, clientId, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['conversationId'],
            $data['senderId'],
            $data['type'] ?? 'text',
            $data['content'] ?? '',
            isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
            $data['clientId'] ?? null,
        ]);

        $stmt = $this->pdo->prepare('SELECT seq, createdAt FROM DirectMessage WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id' => $id,
            'seq' => (int) ($row['seq'] ?? 0),
            'createdAt' => $row['createdAt'] ?? '',
        ];
    }

    /**
     * Check for duplicate clientId (idempotency).
     */
    public function findByClientId(string $senderId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, seq, createdAt FROM DirectMessage WHERE senderId = ? AND clientId = ?'
        );
        $stmt->execute([$senderId, $clientId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get messages for a conversation (cursor-based pagination, ascending).
     *
     * @param int $beforeSeq Load messages before this seq (exclusive)
     * @param int $limit Max messages to return
     * @return array<int, array>
     */
    public function findByConversation(string $conversationId, int $beforeSeq = 0, int $limit = 50): array
    {
        if ($beforeSeq > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT dm.*, u.displayName AS senderDisplayName, u.image AS senderImage
                 FROM DirectMessage dm
                 LEFT JOIN User u ON u.id = dm.senderId
                 WHERE dm.conversationId = ? AND dm.seq < ?
                 ORDER BY dm.seq DESC
                 LIMIT ?'
            );
            $stmt->execute([$conversationId, $beforeSeq, $limit]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT dm.*, u.displayName AS senderDisplayName, u.image AS senderImage
                 FROM DirectMessage dm
                 LEFT JOIN User u ON u.id = dm.senderId
                 WHERE dm.conversationId = ?
                 ORDER BY dm.seq DESC
                 LIMIT ?'
            );
            $stmt->execute([$conversationId, $limit]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Reverse to return ascending order
        return array_reverse($rows);
    }

    /**
     * Get all new messages across all conversations for a user since a seq cursor.
     * Used by the sync endpoint.
     *
     * @return array<int, array>
     */
    public function findNewForUser(string $userId, int $afterSeq, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dm.*, u.displayName AS senderDisplayName, u.image AS senderImage
             FROM DirectMessage dm
             INNER JOIN ChatParticipant cp ON cp.conversationId = dm.conversationId AND cp.userId = ?
             LEFT JOIN User u ON u.id = dm.senderId
             WHERE dm.seq > ?
             ORDER BY dm.seq ASC
             LIMIT ?'
        );
        $stmt->execute([$userId, $afterSeq, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the highest seq for a conversation.
     */
    public function getMaxSeq(string $conversationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(seq), 0) FROM DirectMessage WHERE conversationId = ?'
        );
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Soft-delete a message (for all): set deletedAt, deletedBy, clear content/payload.
     */
    public function markDeleted(string $id, string $deletedBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DirectMessage SET deletedAt = NOW(3), deletedBy = ?, content = \'\', payload = NULL
             WHERE id = ? AND deletedAt IS NULL'
        );
        $stmt->execute([$deletedBy, $id]);
    }

    /**
     * Edit a message: set new content and editedAt.
     */
    public function updateContent(string $id, string $content): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DirectMessage SET content = ?, editedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$content, $id]);
    }

    /**
     * Count messages older than a given date.
     */
    public function countOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM DirectMessage WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete messages older than a given date in batches.
     * Returns the number of deleted rows in this batch.
     */
    public function deleteOlderThanBatch(int $days, int $batchSize = 1000): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM DirectMessage WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?'
        );
        $stmt->execute([$days, $batchSize]);
        return $stmt->rowCount();
    }
}
