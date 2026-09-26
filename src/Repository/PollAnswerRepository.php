<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollAnswerRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollAnswer WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listByResponse(string $responseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollAnswer WHERE responseId = ? ORDER BY createdAt ASC');
        $stmt->execute([$responseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alle Antworten einer Umfrage inkl. Zuordnung zu Response und Nutzer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, r.userId, r.createdAt AS responseCreatedAt
             FROM PollAnswer a
             JOIN PollResponse r ON r.id = a.responseId
             WHERE r.pollId = ?
             ORDER BY r.createdAt ASC, a.createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $responseId, string $questionId, ?string $value): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollAnswer (id, responseId, questionId, value, createdAt) VALUES (?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $responseId, $questionId, $value]);
        return $id;
    }

    public function deleteByResponse(string $responseId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollAnswer WHERE responseId = ?');
        $stmt->execute([$responseId]);
    }
}
