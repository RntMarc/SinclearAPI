<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedPostVoteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByPostAndUser(string $postId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM FeedPostVote WHERE postId = ? AND userId = ?'
        );
        $stmt->execute([$postId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $postId, string $userId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO FeedPostVote (id, postId, userId, createdAt)
             VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $postId, $userId]);
        return $id;
    }

    public function delete(string $postId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM FeedPostVote WHERE postId = ? AND userId = ?'
        );
        $stmt->execute([$postId, $userId]);
    }
}
