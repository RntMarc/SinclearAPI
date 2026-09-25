<?php

namespace Sinclear\Api\Services\Centrifugo;

use Psr\Log\LoggerInterface;

/**
 * Publishes chat events to Centrifugo channels.
 *
 * All methods receive pre-formatted data from DirectMessageService.
 * Errors are logged but never thrown — graceful degradation.
 */
final readonly class ChatEventPublisher
{
    public function __construct(
        private CentrifugoClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * Publish a new message event.
     *
     * @param array{id: string, seq: int, conversationId: string, senderId: string, sender: array, type: string, content: string, payload: mixed, clientId: string|null, replyToMessageId: string|null, replyTo: array|null, editedAt: string|null, deleted: bool, reactions: array, createdAt: string} $formattedMessage
     */
    public function publishMessageCreated(array $formattedMessage): void
    {
        $this->publish($formattedMessage['conversationId'], [
            'type' => 'message_created',
            'message' => $formattedMessage,
        ]);
    }

    /**
     * Publish an edited message event.
     *
     * @param array{id: string, seq: int, conversationId: string, senderId: string, sender: array, type: string, content: string, payload: mixed, clientId: string|null, replyToMessageId: string|null, replyTo: array|null, editedAt: string|null, deleted: bool, reactions: array, createdAt: string} $formattedMessage
     */
    public function publishMessageEdited(array $formattedMessage): void
    {
        $this->publish($formattedMessage['conversationId'], [
            'type' => 'message_edited',
            'message' => $formattedMessage,
        ]);
    }

    /**
     * Publish a deleted message event.
     */
    public function publishMessageDeleted(string $conversationId, string $messageId, int $seq): void
    {
        $this->publish($conversationId, [
            'type' => 'message_deleted',
            'messageId' => $messageId,
            'seq' => $seq,
        ]);
    }

    /**
     * Publish a read-receipt event.
     *
     * Read receipts are ephemeral and must not land in channel history
     * (otherwise they are replayed as missed events on recovery).
     */
    public function publishRead(string $conversationId, int $seq, int $lastReadSeq, string $userId): void
    {
        $this->publish($conversationId, [
            'type' => 'read',
            'seq' => $seq,
            'lastReadSeq' => $lastReadSeq,
            'userId' => $userId,
        ], skipHistory: true);
    }

    private function publish(string $conversationId, array $data, bool $skipHistory = false): void
    {
        try {
            $this->client->publish("chat:{$conversationId}", $data, $skipHistory);
        } catch (\Throwable $e) {
            // Should never happen (CentrifugoClient catches internally),
            // but defensive log if a different exception slips through.
            $this->logger->warning('[CENTRIFUGO] publish failed for chat:{conversationId}: {error}', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
