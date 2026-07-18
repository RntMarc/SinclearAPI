<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedbackCommentRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FeedbackComment WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listBySuggestion(string $suggestionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM FeedbackComment c
             LEFT JOIN User u ON u.id = c.userId
             WHERE c.suggestionId = ?
             ORDER BY c.createdAt ASC'
        );
        $stmt->execute([$suggestionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countBySuggestion(string $suggestionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM FeedbackComment WHERE suggestionId = ? AND text IS NOT NULL'
        );
        $stmt->execute([$suggestionId]);
        return (int) $stmt->fetchColumn();
    }

    public function countBySuggestionForSuggestions(array $suggestionIds): array
    {
        if ($suggestionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($suggestionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT suggestionId, COUNT(*) AS cnt
             FROM FeedbackComment
             WHERE suggestionId IN ($placeholders) AND text IS NOT NULL
             GROUP BY suggestionId"
        );
        $stmt->execute($suggestionIds);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['suggestionId']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function hasReplies(string $commentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM FeedbackComment WHERE parentId = ? AND text IS NOT NULL'
        );
        $stmt->execute([$commentId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO FeedbackComment (id, suggestionId, userId, parentId, text, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['suggestionId'],
            $data['userId'],
            $data['parentId'] ?? null,
            $data['text'],
        ]);
        return $id;
    }

    public function update(string $id, string $text): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE FeedbackComment SET text = ?, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$text, $id]);
    }

    public function softDelete(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE FeedbackComment SET text = NULL, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function hardDelete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedbackComment WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteBySuggestion(string $suggestionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedbackComment WHERE suggestionId = ?');
        $stmt->execute([$suggestionId]);
    }
}
