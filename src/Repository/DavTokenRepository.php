<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DavTokenRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(array $data): array
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DavToken (id, userId, label, keyHash, expiresAt, createdAt)
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

    public function findByHash(string $keyHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, label, keyHash, expiresAt, lastUsedAt, createdAt
             FROM DavToken WHERE keyHash = ?'
        );
        $stmt->execute([$keyHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function countByUser(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM DavToken WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM DavToken WHERE userId = ?'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT id, userId, label, expiresAt, lastUsedAt, createdAt
             FROM DavToken
             WHERE userId = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$userId, $limit, $offset]);
        $tokens = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $tokens,
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
            'DELETE FROM DavToken WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastUsed(string $id, string $olderThan): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DavToken SET lastUsedAt = NOW(3)
             WHERE id = ? AND (lastUsedAt IS NULL OR lastUsedAt < ?)'
        );
        $stmt->execute([$id, $olderThan]);
    }
}
