<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class NotificationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Insert a notification and return the created record (id + createdAt).
     *
     * @return array{id: string, createdAt: string}
     */
    public function create(array $data): array
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO Notification (id, userId, type, title, body, data, isRead, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['type'],
            $data['title'],
            $data['body'],
            $data['data'] !== null ? json_encode($data['data'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        $stmt = $this->pdo->prepare('SELECT createdAt FROM Notification WHERE id = ?');
        $stmt->execute([$id]);
        $created = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id' => $id,
            'createdAt' => $created['createdAt'] ?? '',
        ];
    }

    public function getUnread(string $userId, ?string $since = null): array
    {
        if ($since !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM Notification WHERE userId = ? AND isRead = 0 AND createdAt > ? ORDER BY createdAt DESC LIMIT 50'
            );
            $stmt->execute([$userId, $since]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM Notification WHERE userId = ? AND isRead = 0 ORDER BY createdAt DESC LIMIT 50'
            );
            $stmt->execute([$userId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['data'] = $row['data'] !== null ? json_decode($row['data'], true) : null;
            $row['isRead'] = (bool) $row['isRead'];
            unset($row['title'], $row['body']);
            return $row;
        }, $rows);
    }

    public function markRead(string $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE Notification SET isRead = 1 WHERE userId = ? AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$userId], $ids));
    }
}
