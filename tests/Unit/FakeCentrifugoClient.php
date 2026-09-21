<?php

namespace Sinclear\Api\Tests\Unit;

use Sinclear\Api\Services\Centrifugo\CentrifugoClientInterface;

class FakeCentrifugoClient implements CentrifugoClientInterface
{
    public string $publishedChannel = '';
    public array $publishedData = [];
    public bool $publishedSkipHistory = false;
    public array $presenceResult = [];
    public array $userPresenceResult = ['online' => false, 'lastJoin' => null, 'lastLeave' => null];
    public array $bulkUserPresenceResult = [];
    public string $unsubscribedChannel = '';
    public string $unsubscribedUserId = '';
    public string $disconnectedUserId = '';
    public ?\Throwable $publishException = null;

    public function publish(string $channel, array $data, bool $skipHistory = false): void
    {
        if ($this->publishException !== null) {
            throw $this->publishException;
        }
        $this->publishedChannel = $channel;
        $this->publishedData = $data;
        $this->publishedSkipHistory = $skipHistory;
    }

    public function presence(string $channel): array
    {
        return $this->presenceResult;
    }

    public function getUserPresence(string $userId): array
    {
        return $this->userPresenceResult;
    }

    public function getBulkUserPresence(array $userIds): array
    {
        if ($this->bulkUserPresenceResult !== []) {
            return $this->bulkUserPresenceResult;
        }
        $results = [];
        foreach ($userIds as $userId) {
            $results[$userId] = $this->userPresenceResult;
        }
        return $results;
    }

    public function unsubscribe(string $channel, string $userId): void
    {
        $this->unsubscribedChannel = $channel;
        $this->unsubscribedUserId = $userId;
    }

    public function disconnect(string $userId): void
    {
        $this->disconnectedUserId = $userId;
    }
}
