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
     * Check for duplicate clientId (idempotency) within the same conversation.
     */
    public function findByClientId(string $conversationId, string $senderId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, seq, createdAt FROM DirectMessage
             WHERE conversationId = ? AND senderId = ? AND clientId = ?'
        );
        $stmt->execute([$conversationId, $senderId, $clientId]);
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
     * Find the latest message in a conversation (for preview).
     */
    public function findLastMessage(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content, senderId, createdAt, deletedAt FROM DirectMessage
             WHERE conversationId = ?
             ORDER BY seq DESC LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Count unread messages for a user in a conversation.
     */
    public function countUnread(string $conversationId, string $userId, int $lastReadSeq): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM DirectMessage
             WHERE conversationId = ? AND seq > ? AND senderId != ? AND deletedAt IS NULL'
        );
        $stmt->execute([$conversationId, $lastReadSeq, $userId]);
        return (int) $stmt->fetchColumn();
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
