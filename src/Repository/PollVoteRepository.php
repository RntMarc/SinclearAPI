<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollVoteRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function hasVoted(string $pollId, string $participantHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM PollVote WHERE pollId = ? AND participantHash = ? LIMIT 1');
        $stmt->execute([$pollId, $participantHash]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param string[] $optionIds
     */
    public function create(string $pollId, array $optionIds, string $participantHash): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollVote (id, pollId, optionId, participantHash, createdAt)
             VALUES (?, ?, ?, ?, NOW(3))'
        );
        foreach ($optionIds as $optionId) {
            $stmt->execute([Uuid::uuid7()->toString(), $pollId, $optionId, $participantHash]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollVote WHERE pollId = ? ORDER BY createdAt ASC');
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stimmen pro Option für eine Umfrage.
     *
     * @return array<string, int> optionId => count
     */
    public function countsByOption(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT optionId, COUNT(*) AS votes FROM PollVote WHERE pollId = ? GROUP BY optionId'
        );
        $stmt->execute([$pollId]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['optionId']] = (int) $row['votes'];
        }
        return $counts;
    }

    public function countParticipants(string $pollId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT participantHash) FROM PollVote WHERE pollId = ?'
        );
        $stmt->execute([$pollId]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollVote WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }

    public function deleteByOption(string $optionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollVote WHERE optionId = ?');
        $stmt->execute([$optionId]);
    }
}
