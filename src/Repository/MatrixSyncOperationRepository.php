<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

final readonly class MatrixSyncOperationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function create(string $userId, string $type, ?array $payload): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO MatrixSyncOperation (id, userId, type, payload, status, attempts, createdAt)
             VALUES (?, ?, ?, ?, ?, 0, NOW(3))'
        );
        $stmt->execute([$id, $userId, $type, $payload === null ? null : json_encode($payload)]);
        return $id;
    }

    public function hasPending(string $userId, string $type): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM MatrixSyncOperation
             WHERE userId = ? AND type = ? AND status = ?'
        );
        $stmt->execute([$userId, $type, 'pending']);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $payload */
    public function updatePayloadForPending(string $userId, string $type, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE MatrixSyncOperation SET payload = ? WHERE userId = ? AND type = ? AND status = ?'
        );
        $stmt->execute([json_encode($payload), $userId, $type, 'pending']);
    }

    /**
     * Fällige Operationen (pending und nextAttemptAt NULL oder erreicht).
     *
     * @return list<array<string, mixed>>
     */
    public function findDue(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, type, payload, status, attempts, nextAttemptAt, lastError, createdAt
             FROM MatrixSyncOperation
             WHERE status = ? AND (nextAttemptAt IS NULL OR nextAttemptAt <= NOW(3))
             ORDER BY createdAt ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['pending']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markDone(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE MatrixSyncOperation SET status = ?, completedAt = NOW(3), lastError = NULL, nextAttemptAt = NULL WHERE id = ?'
        );
        $stmt->execute(['done', $id]);
    }

    public function markFailed(string $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE MatrixSyncOperation SET status = ?, lastError = ?, completedAt = NOW(3), nextAttemptAt = NULL WHERE id = ?'
        );
        $stmt->execute(['failed', $error, $id]);
    }

    public function recordTransientFailure(string $id, string $error, string $nextAttemptAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE MatrixSyncOperation SET attempts = attempts + 1, lastError = ?, nextAttemptAt = ? WHERE id = ?'
        );
        $stmt->execute([$error, $nextAttemptAt, $id]);
    }

    /**
     * Aktive (noch nicht abgeschlossene) Operationen fürs Admin-Dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function findActive(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.userId, o.type, o.payload, o.status, o.attempts, o.nextAttemptAt, o.lastError, o.createdAt, o.completedAt,
                    u.displayName
             FROM MatrixSyncOperation o
             JOIN User u ON u.id = o.userId
             WHERE o.status IN (?, ?)
             ORDER BY o.createdAt ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['pending', 'failed']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) AS c FROM MatrixSyncOperation GROUP BY status'
        );
        $counts = ['pending' => 0, 'done' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Setzt eine Operation für einen erneuten Versuch zurück
     * (status=pending, nextAttemptAt=NULL, attempts=0).
     */
    public function resetForRetry(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE MatrixSyncOperation SET status = ?, nextAttemptAt = NULL, attempts = 0, lastError = NULL, completedAt = NULL WHERE id = ?'
        );
        $stmt->execute(['pending', $id]);
        return $stmt->rowCount() > 0;
    }
}
