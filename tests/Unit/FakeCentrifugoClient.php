<?php

namespace Sinclear\Api\Tests\Unit;

use Sinclear\Api\Services\Centrifugo\CentrifugoClientInterface;

class FakeCentrifugoClient implements CentrifugoClientInterface
{
    public string $publishedChannel = '';
    public array $publishedData = [];
    public bool $publishedSkipHistory = false;
    public array $presenceResult = [];
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
