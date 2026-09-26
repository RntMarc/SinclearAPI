<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollResponseRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollResponse WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByPollAndUser(string $pollId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PollResponse WHERE pollId = ? AND userId = ? ORDER BY createdAt ASC LIMIT 1'
        );
        $stmt->execute([$pollId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM PollResponse r
             JOIN User u ON u.id = r.userId
             WHERE r.pollId = ?
             ORDER BY r.createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByPoll(string $pollId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM PollResponse WHERE pollId = ?');
        $stmt->execute([$pollId]);
        return (int) $stmt->fetchColumn();
    }

    public function create(string $pollId, string $userId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollResponse (id, pollId, userId, createdAt, updatedAt)
             VALUES (?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([$id, $pollId, $userId]);
        return $id;
    }

    public function touch(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE PollResponse SET updatedAt = NOW(3) WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollResponse WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollResponse WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }
}
