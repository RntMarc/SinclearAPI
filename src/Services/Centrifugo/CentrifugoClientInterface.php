<?php

namespace Sinclear\Api\Services\Centrifugo;

/**
 * Contract for Centrifugo real-time server communication.
 *
 * Implementations must silently swallow all errors (fire-and-forget).
 */
interface CentrifugoClientInterface
{
    /**
     * Publish data to a Centrifugo channel.
     */
    public function publish(string $channel, array $data): void;

    /**
     * Get presence information for a channel.
     *
     * @return array<string, array{client: string, user: string}> User-ID → info
     */
    public function presence(string $channel): array;

    /**
     * Remove a user from a channel's presence.
     */
    public function unsubscribe(string $channel, string $userId): void;

    /**
     * Disconnect a user from all channels.
     */
    public function disconnect(string $userId): void;
}
