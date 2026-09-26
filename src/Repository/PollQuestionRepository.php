<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollQuestionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PollQuestion WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listByPoll(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM PollQuestion WHERE pollId = ? ORDER BY position ASC, createdAt ASC'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PollQuestion
                (id, pollId, type, title, description, isRequired, position, config, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['pollId'],
            $data['type'],
            $data['title'],
            $data['description'] ?? null,
            (int) ($data['isRequired'] ?? 0),
            (int) ($data['position'] ?? 0),
            isset($data['config']) ? json_encode($data['config'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return $id;
    }

    public function deleteByPoll(string $pollId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PollQuestion WHERE pollId = ?');
        $stmt->execute([$pollId]);
    }
}
