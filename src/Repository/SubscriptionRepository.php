<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class SubscriptionRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO Subscription (id, name, billingPeriodStart, billingPeriodEnd, basePrice)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['billingPeriodStart'],
            $data['billingPeriodEnd'],
            $data['basePrice'],
        ]);

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $fields = [];
        $params = [];

        foreach (['name', 'billingPeriodStart', 'billingPeriodEnd', 'basePrice'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = ?";
                $params[] = $data[$field];
            }
        }

        if ($fields === []) {
            return;
        }

        $params[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE Subscription SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM SubscriptionRelation WHERE subscriptionId = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM Subscription WHERE id = ?')->execute([$id]);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Subscription WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, sr.id AS relationId, sr.isUser, sr.userName, sr.hasPaid
             FROM Subscription s
             JOIN SubscriptionRelation sr ON sr.subscriptionId = s.id
             WHERE sr.userId = ?
             ORDER BY s.name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdWithAccess(string $id, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, sr.id AS relationId, sr.isUser, sr.userName, sr.hasPaid
             FROM Subscription s
             JOIN SubscriptionRelation sr ON sr.subscriptionId = s.id
             WHERE s.id = ? AND sr.userId = ?'
        );
        $stmt->execute([$id, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findParticipants(string $subscriptionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.id, sr.userId, sr.userName, sr.isUser, sr.hasPaid,
                    u.displayName AS userDisplayName, u.image AS userImage
             FROM SubscriptionRelation sr
             LEFT JOIN User u ON u.id = sr.userId
             WHERE sr.subscriptionId = ?
             ORDER BY sr.userName ASC, sr.userId ASC'
        );
        $stmt->execute([$subscriptionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, COUNT(sr.id) AS participantCount
             FROM Subscription s
             LEFT JOIN SubscriptionRelation sr ON sr.subscriptionId = s.id
             GROUP BY s.id
             ORDER BY s.name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addParticipant(array $data): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO SubscriptionRelation (id, subscriptionId, userId, isUser, userName, hasPaid)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['subscriptionId'],
            $data['userId'] ?? null,
            $data['isUser'] ?? 1,
            $data['userName'] ?? null,
            $data['hasPaid'] ?? 0,
        ]);

        return $id;
    }

    public function removeParticipant(string $id): void
    {
        $this->pdo->prepare('DELETE FROM SubscriptionRelation WHERE id = ?')->execute([$id]);
    }

    public function findParticipantById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM SubscriptionRelation WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function hasAccess(string $subscriptionId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM SubscriptionRelation WHERE subscriptionId = ? AND userId = ?'
        );
        $stmt->execute([$subscriptionId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
