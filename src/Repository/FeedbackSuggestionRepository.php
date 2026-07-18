<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedbackSuggestionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FeedbackSuggestion WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function list(int $page, int $limit, ?string $userId = null): array
    {
        $countStmt = $this->pdo->query('SELECT COUNT(*) FROM FeedbackSuggestion');
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;

        $sql = 'SELECT s.*, u.displayName AS userDisplayName, u.image AS userImage, COUNT(v.id) AS upvoteCount, COUNT(fc.id) AS commentCount';
        if ($userId !== null) {
            $sql .= ', COALESCE(MAX(CASE WHEN v.userId = ? THEN 1 END), 0) AS hasVoted';
        }
        $sql .= ' FROM FeedbackSuggestion s'
            . ' LEFT JOIN FeedbackVote v ON v.suggestionId = s.id'
            . ' LEFT JOIN FeedbackComment fc ON fc.suggestionId = s.id AND fc.text IS NOT NULL'
            . ' LEFT JOIN User u ON u.id = s.userId';
        if ($userId !== null) {
            $sql .= ' GROUP BY s.id ORDER BY upvoteCount DESC, s.createdAt DESC LIMIT ? OFFSET ?';
            $dataStmt = $this->pdo->prepare($sql);
            $dataStmt->execute([$userId, $limit, $offset]);
        } else {
            $sql .= ' GROUP BY s.id ORDER BY upvoteCount DESC, s.createdAt DESC LIMIT ? OFFSET ?';
            $dataStmt = $this->pdo->prepare($sql);
            $dataStmt->execute([$limit, $offset]);
        }

        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO FeedbackSuggestion (id, userId, title, description, createdAt, updatedAt, status)
             VALUES (?, ?, ?, ?, NOW(3), NOW(3), ?)'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['title'],
            $data['description'] ?? null,
            $data['status'] ?? 'submitted',
        ]);
        return $id;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedbackSuggestion WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function updateStatus(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE FeedbackSuggestion SET status = ?, updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }

    public function getUpvoteCount(string $suggestionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM FeedbackVote WHERE suggestionId = ?');
        $stmt->execute([$suggestionId]);
        return (int) $stmt->fetchColumn();
    }
}
