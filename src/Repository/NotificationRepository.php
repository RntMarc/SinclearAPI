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
        $dedupeKey = $data['dedupeKey'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO Notification (id, userId, type, dedupeKey, title, body, data, isRead, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['type'],
            $dedupeKey,
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

    /**
     * Coalesced upsert: if an unread notification with the same dedupeKey exists,
     * update its title/body/data instead of creating a new one.
     * Returns the (existing or new) notification id and createdAt.
     *
     * @param array{id: string, createdAt: string}|null $existing Existing unread notification with this dedupeKey
     * @return array{id: string, createdAt: string}
     */
    public function coalescedUpsert(array $data, ?array $existing = null): array
    {
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE Notification SET title = ?, body = ?, data = ?, createdAt = NOW(3)
                 WHERE id = ? AND isRead = 0'
            );
            $stmt->execute([
                $data['title'],
                $data['body'],
                $data['data'] !== null ? json_encode($data['data'], JSON_UNESCAPED_UNICODE) : null,
                $existing['id'],
            ]);

            return [
                'id' => $existing['id'],
                'createdAt' => $existing['createdAt'],
            ];
        }

        return $this->create($data);
    }

    /**
     * Find an unread notification by dedupeKey for a user.
     */
    public function findUnreadByDedupeKey(string $userId, string $dedupeKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, createdAt FROM Notification
             WHERE userId = ? AND dedupeKey = ? AND isRead = 0
             ORDER BY createdAt DESC LIMIT 1'
        );
        $stmt->execute([$userId, $dedupeKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
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
            $row['text'] = $row['body'];
            unset($row['body']);
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
