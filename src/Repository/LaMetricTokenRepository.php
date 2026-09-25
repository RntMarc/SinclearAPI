<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class LaMetricTokenRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Ersetzt ein vorhandenes Token des Nutzers (genau eines pro Nutzer)
     * und legt ein neues an. Gibt die gespeicherten Daten zurück.
     *
     * @param array{userId: string, label: string, token: string, expiresAt: string} $data
     * @return array<string, mixed>
     */
    public function upsert(array $data): array
    {
        $this->pdo->beginTransaction();

        try {
            $delete = $this->pdo->prepare('DELETE FROM LaMetricToken WHERE userId = ?');
            $delete->execute([$data['userId']]);

            $id = Uuid::uuid7()->toString();
            $stmt = $this->pdo->prepare(
                'INSERT INTO LaMetricToken (id, userId, label, token, expiresAt, lastUsedAt, createdAt)
                 VALUES (?, ?, ?, ?, ?, NULL, NOW(3))'
            );
            $stmt->execute([
                $id,
                $data['userId'],
                $data['label'],
                $data['token'],
                $data['expiresAt'],
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'id' => $id,
            'userId' => $data['userId'],
            'label' => $data['label'],
            'token' => $data['token'],
            'expiresAt' => $data['expiresAt'],
            'lastUsedAt' => null,
            'createdAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, label, token, expiresAt, lastUsedAt, createdAt
             FROM LaMetricToken WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, label, token, expiresAt, lastUsedAt, createdAt
             FROM LaMetricToken WHERE token = ?'
        );
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function deleteByUserId(string $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM LaMetricToken WHERE userId = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastUsed(string $id, string $olderThan): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE LaMetricToken SET lastUsedAt = NOW(3)
             WHERE id = ? AND (lastUsedAt IS NULL OR lastUsedAt < ?)'
        );
        $stmt->execute([$id, $olderThan]);
    }
}
