<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollInviteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollInvite WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByPollAndUser(string $pollId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollInvite WHERE pollId = ? AND userId = ?');
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
            'SELECT i.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM PollInvite i
             JOIN User u ON u.id = i.userId
             WHERE i.pollId = ?
             ORDER BY i.createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    public function listInvitedUserIds(string $pollId): array
    {
        $stmt = $this->pdo->prepare('SELECT userId FROM PollInvite WHERE pollId = ?');
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function create(string $pollId, string $userId, bool $isIndispensable = false): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollInvite (id, pollId, userId, isIndispensable, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $pollId, $userId, $isIndispensable ? 1 : 0]);
        return $id;
    }

    public function delete(string $pollId, string $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollInvite WHERE pollId = ? AND userId = ?');
        $stmt->execute([$pollId, $userId]);
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollInvite WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }
}
