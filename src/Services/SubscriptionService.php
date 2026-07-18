<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\SubscriptionRepository;

final readonly class SubscriptionService
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepo,
    ) {}

    public function listByUser(string $userId, bool $adminAll = false): array
    {
        if ($adminAll) {
            $subscriptions = $this->subscriptionRepo->findAll();

            return array_map(function (array $sub) {
                return $this->enrichAdmin($sub);
            }, $subscriptions);
        }

        $subscriptions = $this->subscriptionRepo->findByUserId($userId);

        return array_map(function (array $sub) use ($userId) {
            return $this->enrich($sub, $userId);
        }, $subscriptions);
    }

    public function get(string $id, string $userId): array
    {
        $subscription = $this->subscriptionRepo->findByIdWithAccess($id, $userId);
        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        return $this->enrich($subscription, $userId);
    }

    public function getParticipants(string $subscriptionId, string $userId): array
    {
        $subscription = $this->subscriptionRepo->findByIdWithAccess($subscriptionId, $userId);
        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        return $this->subscriptionRepo->findParticipants($subscriptionId);
    }

    public function create(array $data): array
    {
        $id = $this->subscriptionRepo->create($data);

        $subscription = $this->subscriptionRepo->findById($id);
        return $this->enrichAdmin($subscription);
    }

    public function update(string $id, array $data): array
    {
        $subscription = $this->subscriptionRepo->findById($id);
        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        $this->subscriptionRepo->update($id, $data);

        $subscription = $this->subscriptionRepo->findById($id);
        return $this->enrichAdmin($subscription);
    }

    public function delete(string $id): void
    {
        $subscription = $this->subscriptionRepo->findById($id);
        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        $this->subscriptionRepo->delete($id);
    }

    public function listAll(): array
    {
        $subscriptions = $this->subscriptionRepo->findAll();

        return array_map(function (array $sub) {
            return $this->enrichAdmin($sub);
        }, $subscriptions);
    }

    public function addParticipant(string $subscriptionId, array $data): array
    {
        $subscription = $this->subscriptionRepo->findById($subscriptionId);
        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        $participantData = array_merge($data, ['subscriptionId' => $subscriptionId]);
        $participantId = $this->subscriptionRepo->addParticipant($participantData);

        return $this->subscriptionRepo->findParticipantById($participantId);
    }

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->subscriptionRepo->findParticipantById($participantId);
        if ($participant === null) {
            throw new \RuntimeException('Participant not found');
        }

        $this->subscriptionRepo->removeParticipant($participantId);
    }

    public function updateParticipant(string $participantId, array $data): array
    {
        $participant = $this->subscriptionRepo->findParticipantById($participantId);
        if ($participant === null) {
            throw new \RuntimeException('Participant not found');
        }

        $this->subscriptionRepo->updateParticipant($participantId, $data);

        return $this->subscriptionRepo->findParticipantById($participantId);
    }

    private function enrich(array $subscription, string $userId): array
    {
        $result = [
            'id' => $subscription['id'],
            'name' => $subscription['name'],
            'billingPeriodStart' => $subscription['billingPeriodStart'],
            'billingPeriodEnd' => $subscription['billingPeriodEnd'],
            'basePrice' => (float) $subscription['basePrice'],
            'hasPaid' => (bool) $subscription['hasPaid'],
        ];

        if (!empty($subscription['userName'])) {
            $result['userName'] = $subscription['userName'];
        }

        return $result;
    }

    private function enrichAdmin(array $subscription): array
    {
        return [
            'id' => $subscription['id'],
            'name' => $subscription['name'],
            'billingPeriodStart' => $subscription['billingPeriodStart'],
            'billingPeriodEnd' => $subscription['billingPeriodEnd'],
            'basePrice' => (float) $subscription['basePrice'],
            'participantCount' => (int) ($subscription['participantCount'] ?? 0),
        ];
    }
}
