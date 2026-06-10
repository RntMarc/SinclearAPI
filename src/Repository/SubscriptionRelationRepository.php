<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for SubscriptionRelation table.
 */
final class SubscriptionRelationRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'SubscriptionRelation';
    }

    protected function columns(): array
    {
        return ['id', 'subscriptionId', 'userId', 'isUser', 'userName', 'hasPaid'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sr.*, s.name as subscriptionName, s.basePrice, s.billingPeriodStart, s.billingPeriodEnd
             FROM `SubscriptionRelation` sr
             JOIN `Subscription` s ON sr.subscriptionId = s.id
             WHERE sr.userId = :userId'
        );
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }
}
