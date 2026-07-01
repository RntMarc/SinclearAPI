<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedPostRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FeedPosts WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByForum(string $forumId, int $page, int $limit, ?string $userId): array
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM FeedPosts WHERE forumId = ?');
        $countStmt->execute([$forumId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;

        $sql = 'SELECT p.*, COUNT(v.id) AS upvoteCount, COUNT(fc.id) AS commentCount';
        if ($userId !== null) {
            $sql .= ', COALESCE(MAX(CASE WHEN v.userId = ? THEN 1 END), 0) AS hasVoted';
        }
        $sql .= ' FROM FeedPosts p'
            . ' LEFT JOIN FeedPostVote v ON v.postId = p.id'
            . ' LEFT JOIN FeedPostComment fc ON fc.postId = p.id AND fc.text IS NOT NULL'
            . ' WHERE p.forumId = ?';
        if ($userId !== null) {
            $sql .= ' GROUP BY p.id ORDER BY p.createdAt DESC LIMIT ? OFFSET ?';
            $dataStmt = $this->pdo->prepare($sql);
            $dataStmt->execute([$userId, $forumId, $limit, $offset]);
        } else {
            $sql .= ' GROUP BY p.id ORDER BY p.createdAt DESC LIMIT ? OFFSET ?';
            $dataStmt = $this->pdo->prepare($sql);
            $dataStmt->execute([$forumId, $limit, $offset]);
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
            'INSERT INTO FeedPosts (id, userId, type, content, createdAt, updatedAt, forumId)
             VALUES (?, ?, ?, ?, NOW(3), NOW(3), ?)'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['type'],
            json_encode($data['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $data['forumId'],
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (array_key_exists('content', $data)) {
            $sets[] = 'content = ?';
            $values[] = json_encode($data['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (array_key_exists('type', $data)) {
            $sets[] = 'type = ?';
            $values[] = $data['type'];
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $id;

        $sql = 'UPDATE FeedPosts SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM FeedPosts WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getUpvoteCount(string $postId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM FeedPostVote WHERE postId = ?');
        $stmt->execute([$postId]);
        return (int) $stmt->fetchColumn();
    }
}
