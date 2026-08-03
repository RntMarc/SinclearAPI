<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class McpApiKeyRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(array $data): array
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO McpApiKey (id, userId, label, keyHash, expiresAt, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['label'],
            $data['keyHash'],
            $data['expiresAt'],
        ]);

        return [
            'id' => $id,
            'userId' => $data['userId'],
            'label' => $data['label'],
            'keyHash' => $data['keyHash'],
            'expiresAt' => $data['expiresAt'],
        ];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM McpApiKey WHERE id = ?'
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByHash(string $keyHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM McpApiKey WHERE keyHash = ?'
        );
        $stmt->execute([$keyHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function countByUser(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM McpApiKey WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM McpApiKey WHERE userId = ?'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT id, userId, label, expiresAt, createdAt
             FROM McpApiKey
             WHERE userId = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$userId, $limit, $offset]);
        $keys = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $keys,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function delete(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM McpApiKey WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
