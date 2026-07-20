<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ForumRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Forum WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function list(int $page, int $limit, ?string $userId = null): array
    {
        if ($userId !== null) {
            $where = ' WHERE f.id NOT IN (
                SELECT tt.forumId FROM TravelTrip tt
                WHERE tt.forumId IS NOT NULL
                AND tt.forumId NOT IN (SELECT fm.forumId FROM ForumMember fm WHERE fm.userId = ?)
            )';
            $params = [$userId];

            $totalStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM Forum f' . $where
            );
            $totalStmt->execute($params);
            $total = (int) $totalStmt->fetchColumn();

            $offset = ($page - 1) * $limit;
            $params[] = $limit;
            $params[] = $offset;
            $stmt = $this->pdo->prepare(
                'SELECT f.* FROM Forum f' . $where . ' ORDER BY f.createdAt DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute($params);
        } else {
            $total = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM Forum f'
            )->fetchColumn();

            $offset = ($page - 1) * $limit;
            $stmt = $this->pdo->prepare(
                'SELECT f.* FROM Forum f ORDER BY f.createdAt DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function isTripLinked(string $forumId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM TravelTrip WHERE forumId = ? LIMIT 1'
        );
        $stmt->execute([$forumId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Forum (id, name, description, image, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? null,
            $data['image'] ?? null,
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        foreach (['name', 'description', 'image'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $id;

        $sql = 'UPDATE Forum SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM Forum WHERE id = ?');
        $stmt->execute([$id]);
    }
}
