<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class PushSubscriptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function upsert(array $data): string
    {
        $existing = $this->findByEndpoint($data['endpoint']);

        if ($existing !== null) {
            // Re-registration proves the client is alive again: refresh
            // lastSeenAt and reset the failure tracking (heals long-offline
            // devices before the stale sweep removes them).
            $stmt = $this->pdo->prepare(
                'UPDATE PushSubscription
                 SET type = ?, p256dh = ?, auth = ?, userAgent = ?,
                     lastSeenAt = NOW(3), consecutiveFailures = 0,
                     lastErrorAt = NULL, lastError = NULL
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['type'],
                $data['p256dh'] ?? null,
                $data['auth'] ?? null,
                $data['userAgent'] ?? null,
                $existing['id'],
            ]);
            return $existing['id'];
        }

        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO PushSubscription (id, userId, `type`, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['userId'],
            $data['type'],
            $data['endpoint'],
            $data['p256dh'] ?? null,
            $data['auth'] ?? null,
            $data['userAgent'] ?? null,
        ]);
        return $id;
    }

    public function findByEndpoint(string $endpoint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM PushSubscription WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function deleteByUserAndEndpoint(string $userId, string $endpoint): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PushSubscription WHERE userId = ? AND endpoint = ?');
        $stmt->execute([$userId, $endpoint]);
    }

    public function findByUserId(string $userId, ?string $type = null): array
    {
        if ($type !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM PushSubscription WHERE userId = ? AND `type` = ?');
            $stmt->execute([$userId, $type]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM PushSubscription WHERE userId = ?');
            $stmt->execute([$userId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteById(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PushSubscription WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM PushSubscription WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
    }

    public function markSuccess(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE PushSubscription
             SET lastSuccessAt = NOW(3), lastErrorAt = NULL, lastError = NULL, consecutiveFailures = 0
             WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function markFailure(string $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE PushSubscription
             SET consecutiveFailures = consecutiveFailures + 1, lastErrorAt = NOW(3), lastError = ?
             WHERE id = ?'
        );
        $stmt->execute([mb_substr($error, 0, 255), $id]);
    }
}
