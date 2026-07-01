<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedPostCommentRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FeedPostComment WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByPost(string $postId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM FeedPostComment WHERE postId = ? ORDER BY createdAt ASC'
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByPost(string $postId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM FeedPostComment WHERE postId = ? AND text IS NOT NULL'
        );
        $stmt->execute([$postId]);
        return (int) $stmt->fetchColumn();
    }

    public function hasReplies(string $commentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM FeedPostComment WHERE parentId = ? AND text IS NOT NULL'
        );
        $stmt->execute([$commentId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO FeedPostComment (id, postId, userId, parentId, text, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['postId'],
            $data['userId'],
            $data['parentId'] ?? null,
            $data['text'],
        ]);
        return $id;
    }

    public function update(string $id, string $text): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE FeedPostComment SET text = ?, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$text, $id]);
    }

    public function softDelete(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE FeedPostComment SET text = NULL, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function hardDelete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedPostComment WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByPost(string $postId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedPostComment WHERE postId = ?');
        $stmt->execute([$postId]);
    }
}
