<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollAvailabilityVoteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByPollOptionAndUser(string $pollId, string $optionId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PollAvailabilityVote WHERE pollId = ? AND optionId = ? AND userId = ?'
        );
        $stmt->execute([$pollId, $optionId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, u.displayName AS userDisplayName, u.image AS userImage
             FROM PollAvailabilityVote v
             JOIN User u ON u.id = v.userId
             WHERE v.pollId = ?
             ORDER BY v.createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByPollAndUser(string $pollId, string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollAvailabilityVote WHERE pollId = ? AND userId = ?');
        $stmt->execute([$pollId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, string> $availabilityByOption optionId => availability
     */
    public function replaceForUser(string $pollId, string $userId, array $availabilityByOption): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM PollAvailabilityVote WHERE pollId = ? AND userId = ?');
            $delete->execute([$pollId, $userId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO PollAvailabilityVote
                    (id, pollId, optionId, userId, availability, createdAt, updatedAt)
                 VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))'
            );
            foreach ($availabilityByOption as $optionId => $availability) {
                $insert->execute([Uuid::uuid7()->toString(), $pollId, $optionId, $userId, $availability]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteByOption(string $optionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollAvailabilityVote WHERE optionId = ?');
        $stmt->execute([$optionId]);
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollAvailabilityVote WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }

    public function hasAnyVoteForPoll(string $pollId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM PollAvailabilityVote WHERE pollId = ? LIMIT 1');
        $stmt->execute([$pollId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return string[] */
    public function listUserIdsWithVotes(string $pollId): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT userId FROM PollAvailabilityVote WHERE pollId = ?');
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
