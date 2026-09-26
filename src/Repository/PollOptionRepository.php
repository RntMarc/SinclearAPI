<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollOptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollOption WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PollOption WHERE pollId = ? ORDER BY position ASC, createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function listByQuestion(string $questionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PollOption WHERE questionId = ? ORDER BY position ASC, createdAt ASC'
        );
        $stmt->execute([$questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollOption
                (id, pollId, questionId, label, allDay, timezone, startAt, endAt, startDate, endDate,
                 isCounterProposal, proposedBy, position, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['pollId'],
            $data['questionId'] ?? null,
            $data['label'] ?? null,
            (int) ($data['allDay'] ?? 0),
            $data['timezone'] ?? null,
            $data['startAt'] ?? null,
            $data['endAt'] ?? null,
            $data['startDate'] ?? null,
            $data['endDate'] ?? null,
            (int) ($data['isCounterProposal'] ?? 0),
            $data['proposedBy'] ?? null,
            (int) ($data['position'] ?? 0),
        ]);
        return $id;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollOption WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollOption WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }
}
