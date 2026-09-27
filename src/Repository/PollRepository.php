<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PollRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM Poll p
             JOIN User u ON u.id = p.creatorId
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Sichtbare Umfragen für einen Nutzer: eigener Poll, `all_users` oder
     * Einladung. `type`/`status` sind optionale Filter.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForUser(
        string $userId,
        ?string $type,
        ?string $status,
        int $page,
        int $limit,
    ): array {
        $where = '(p.creatorId = ? OR p.accessMode = \'all_users\''
            . ' OR EXISTS (SELECT 1 FROM PollInvite i WHERE i.pollId = p.id AND i.userId = ?))';
        $params = [$userId, $userId];

        if ($type !== null) {
            $where .= ' AND p.type = ?';
            $params[] = $type;
        }
        if ($status !== null) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM Poll p WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT p.*, u.displayName AS creatorDisplayName, u.image AS creatorImage
             FROM Poll p
             JOIN User u ON u.id = p.creatorId
             WHERE $where
             ORDER BY p.createdAt DESC
             LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);

        return [
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            ],
        ];
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Poll
                (id, type, creatorId, title, description, status, closesAt, accessMode,
                 submissionMode, resultsVisibility, allowCounterProposals, allowMultiple, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['type'],
            $data['creatorId'],
            $data['title'],
            $data['description'] ?? null,
            $data['status'] ?? 'open',
            $data['closesAt'] ?? null,
            $data['accessMode'] ?? 'invited',
            $data['submissionMode'] ?? 'single',
            $data['resultsVisibility'] ?? 'creator',
            (int) ($data['allowCounterProposals'] ?? 0),
            (int) ($data['allowMultiple'] ?? 0),
        ]);
        return $id;
    }

    /**
     * Aktualisiert nur die übergebenen Felder aus der Allowlist.
     *
     * @param array<string, mixed> $fields
     */
    public function update(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowed = [
            'title', 'description', 'status', 'closesAt', 'accessMode',
            'submissionMode', 'resultsVisibility', 'allowCounterProposals',
            'allowMultiple', 'finalizedOptionId', 'reminderSentAt',
        ];

        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = "`$column` = ?";
            $params[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE Poll SET ' . implode(', ', $sets) . ', updatedAt = NOW(3) WHERE id = ?'
        );
        $stmt->execute($params);
    }

    public function close(string $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE Poll SET status = 'closed', updatedAt = NOW(3) WHERE id = ? AND status = 'open'"
        );
        $stmt->execute([$id]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM Poll WHERE id = ?');
        $stmt->execute([$id]);
    }
}
