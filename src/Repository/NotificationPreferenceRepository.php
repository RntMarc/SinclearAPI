<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class NotificationPreferenceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, type, state, data, createdAt, updatedAt
             FROM NotificationPreference
             WHERE userId = ?
             ORDER BY type ASC'
        );
        $stmt->execute([$userId]);

        return array_map(function (array $row): array {
            $row['data'] = $row['data'] !== null ? json_decode($row['data'], true) : null;
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByUserAndType(string $userId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, type, state, data, createdAt, updatedAt
             FROM NotificationPreference
             WHERE userId = ? AND type = ?'
        );
        $stmt->execute([$userId, $type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            return null;
        }

        $result['data'] = $result['data'] !== null ? json_decode($result['data'], true) : null;
        return $result;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function upsert(string $userId, string $type, string $state, ?array $data): void
    {
        $existing = $this->findByUserAndType($userId, $type);

        if ($existing === null) {
            $id = Uuid::uuid7()->toString();
            $stmt = $this->pdo->prepare(
                'INSERT INTO NotificationPreference (id, userId, type, state, data, createdAt, updatedAt)
                 VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3))'
            );
            $stmt->execute([
                $id,
                $userId,
                $type,
                $state,
                $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE NotificationPreference SET state = ?, data = ?, updatedAt = NOW(3)
             WHERE userId = ? AND type = ?'
        );
        $stmt->execute([
            $state,
            $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            $userId,
            $type,
        ]);
    }

    public function deleteByUserAndType(string $userId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM NotificationPreference WHERE userId = ? AND type = ?'
        );
        $stmt->execute([$userId, $type]);
    }
}
