<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DiscoverBookmarkRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function find(string $userId, string $placeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM DiscoverBookmark WHERE userId = ? AND placeId = ?'
        );
        $stmt->execute([$userId, $placeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $userId, string $placeId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DiscoverBookmark (id, userId, placeId, createdAt) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $userId, $placeId]);
        return $id;
    }

    public function delete(string $userId, string $placeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM DiscoverBookmark WHERE userId = ? AND placeId = ?'
        );
        $stmt->execute([$userId, $placeId]);
    }

    public function listByUser(string $userId, int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM DiscoverBookmark WHERE userId = ?'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT p.*, b.createdAt as bookmarkedAt
             FROM DiscoverBookmark b
             JOIN DiscoverPlace p ON p.id = b.placeId
             WHERE b.userId = ?
             ORDER BY b.createdAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$userId, $limit, $offset]);
        $places = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $places,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

}
