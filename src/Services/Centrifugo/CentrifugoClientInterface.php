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
     *
     * @param bool $skipHistory Skip adding the publication to the channel history
     *                          (used for ephemeral events like typing/read)
     */
    public function publish(string $channel, array $data, bool $skipHistory = false): void;

    /**
     * Get presence information for a channel.
     *
     * Centrifugo keys presence by client ID; this normalizes it to unique
     * user IDs so callers can match against `ChatParticipant.userId`.
     *
     * @return array<string, array{client: string, user: string}> User-ID → info
     */
    public function presence(string $channel): array;

    /**
     * Get user presence for the user:presence channel.
     *
     * Checks if a user is currently connected via their personal user:<userId> channel.
     * Returns normalized presence with online status and timestamps.
     *
     * @return array{online: bool, lastJoin: ?string, lastLeave: ?string}
     */
    public function getUserPresence(string $userId): array;

    /**
     * Get user presence for multiple users in bulk.
     *
     * @param list<string> $userIds
     * @return array<string, array{online: bool, lastJoin: ?string, lastLeave: ?string}> User-ID → presence info
     */
    public function getBulkUserPresence(array $userIds): array;

    /**
     * Remove a user from a channel's presence.
     */
    public function unsubscribe(string $channel, string $userId): void;

    /**
     * Disconnect a user from all channels.
     */
    public function disconnect(string $userId): void;
}
