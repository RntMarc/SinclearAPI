<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class FeedbackVoteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findBySuggestionAndUser(string $suggestionId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM FeedbackVote WHERE suggestionId = ? AND userId = ?'
        );
        $stmt->execute([$suggestionId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $suggestionId, string $userId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO FeedbackVote (id, suggestionId, userId, createdAt)
             VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $suggestionId, $userId]);
        return $id;
    }

    public function delete(string $suggestionId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM FeedbackVote WHERE suggestionId = ? AND userId = ?'
        );
        $stmt->execute([$suggestionId, $userId]);
    }
}
