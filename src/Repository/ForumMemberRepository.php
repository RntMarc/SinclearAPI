<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ForumMemberRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByForumAndUser(string $forumId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ForumMember WHERE forumId = ? AND userId = ?'
        );
        $stmt->execute([$forumId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listByForum(string $forumId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fm.*, u.displayName, u.image
             FROM ForumMember fm
             JOIN User u ON u.id = fm.userId
             WHERE fm.forumId = ?
             ORDER BY fm.createdAt ASC'
        );
        $stmt->execute([$forumId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByForum(string $forumId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ForumMember WHERE forumId = ?'
        );
        $stmt->execute([$forumId]);
        return (int) $stmt->fetchColumn();
    }

    public function countByForums(array $forumIds): array
    {
        if ($forumIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($forumIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT forumId, COUNT(*) AS cnt
             FROM ForumMember
             WHERE forumId IN ($placeholders)
             GROUP BY forumId"
        );
        $stmt->execute($forumIds);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['forumId']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function join(string $forumId, string $userId): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ForumMember (id, forumId, userId, createdAt)
             VALUES (?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $forumId, $userId]);
        return $id;
    }

    public function leave(string $forumId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ForumMember WHERE forumId = ? AND userId = ?'
        );
        $stmt->execute([$forumId, $userId]);
    }

    public function updateNotifications(string $forumId, string $userId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ForumMember SET notificationsEnabled = ? WHERE forumId = ? AND userId = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $forumId, $userId]);
    }
}
