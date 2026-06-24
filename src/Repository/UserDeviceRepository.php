<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class UserDeviceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, deviceName, platform, pushToken, pushEnabled, lastActiveAt, createdAt
             FROM UserDevice WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, deviceName, platform, pushEnabled, lastActiveAt, createdAt
             FROM UserDevice WHERE userId = ? ORDER BY lastActiveAt DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPushEnabledDevices(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pushToken, platform
             FROM UserDevice WHERE userId = ? AND pushEnabled = 1 AND pushToken IS NOT NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByPushToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, platform FROM UserDevice WHERE pushToken = ?'
        );
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $userId, string $token, string $platform, ?string $deviceName): string
    {
        $existing = $this->findByPushToken($token);
        if ($existing !== null) {
            $this->updateLastActive($existing['id']);
            return $existing['id'];
        }

        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO UserDevice (id, userId, deviceName, platform, pushToken, pushEnabled, lastActiveAt, createdAt)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        );
        $stmt->execute([$id, $userId, $deviceName, $platform, $token]);
        return $id;
    }

    public function enablePush(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE UserDevice SET pushEnabled = 1 WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function disablePush(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE UserDevice SET pushEnabled = 0 WHERE id = ? AND userId = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function updateLastActive(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE UserDevice SET lastActiveAt = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function delete(string $id, string $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM UserDevice WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteByPushToken(string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM UserDevice WHERE pushToken = ?');
        $stmt->execute([$token]);
    }
}
