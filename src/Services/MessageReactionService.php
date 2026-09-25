<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\DirectMessageRepository;
use Sinclear\Api\Repository\MessageReactionRepository;

/**
 * Applies and aggregates chat message reactions.
 *
 * Reactions are fixed to a small allowlist. Emojis are stored in a normalized
 * form (Unicode variation selectors stripped) so that "❤" and "❤️" collapse to
 * the same reaction instead of producing two rows.
 */
final readonly class MessageReactionService
{
    /**
     * Normalized (variation-selector-free) allowlist. The client mirrors this
     * exact list in `kReactionEmojis`.
     */
    public const array ALLOWED_EMOJIS = ['👍', '❤', '😂', '😮', '😢', '🎉', '🔥', '👏'];

    public function __construct(
        private MessageReactionRepository $reactionRepo,
        private DirectMessageRepository $messageRepo,
    ) {}

    /**
     * Add or remove a reaction and return the updated summary for the message.
     *
     * @return array<int, array{emoji: string, count: int, users: array<int, array{id: string, displayName: string|null, avatar: string|null}>}>
     */
    public function applyReaction(
        string $userId,
        string $conversationId,
        string $messageId,
        string $emoji,
        bool $add,
    ): array {
        $emoji = self::normalizeEmoji($emoji);
        if ($emoji === '' || !in_array($emoji, self::ALLOWED_EMOJIS, true)) {
            throw new \RuntimeException('invalid_emoji');
        }

        if ($messageId === '') {
            throw new \RuntimeException('message_not_found');
        }

        $message = $this->messageRepo->findById($messageId);
        if ($message === null || $message['conversationId'] !== $conversationId) {
            throw new \RuntimeException('message_not_found');
        }
        if ($message['deletedAt'] !== null) {
            throw new \RuntimeException('message_deleted');
        }

        if ($add) {
            $this->reactionRepo->add($messageId, $userId, $emoji);
        } else {
            $this->reactionRepo->remove($messageId, $userId, $emoji);
        }

        return $this->forMessage($messageId);
    }

    /**
     * Aggregated reaction summary for a single message.
     *
     * @return array<int, array{emoji: string, count: int, users: array<int, array{id: string, displayName: string|null, avatar: string|null}>}>
     */
    public function forMessage(string $messageId): array
    {
        return $this->aggregate($this->reactionRepo->findByMessageId($messageId));
    }

    /**
     * Aggregated reaction summaries for many messages (messageId → summary).
     *
     * @param array<int, string> $messageIds
     * @return array<string, array<int, array{emoji: string, count: int, users: array<int, array{id: string, displayName: string|null, avatar: string|null}>}>>
     */
    public function forMessages(array $messageIds): array
    {
        $summaries = [];
        foreach ($this->reactionRepo->findByMessageIds($messageIds) as $messageId => $rows) {
            $summaries[$messageId] = $this->aggregate($rows);
        }
        return $summaries;
    }

    /**
     * Remove all reactions of a message (e.g. when it is deleted for all).
     */
    public function clearMessage(string $messageId): void
    {
        $this->reactionRepo->deleteByMessageId($messageId);
    }

    /**
     * Group reaction rows by emoji, newest counts first, stable on ties.
     *
     * @param array<int, array{messageId: string, emoji: string, userId: string, displayName: string|null, image: string|null}> $rows
     * @return array<int, array{emoji: string, count: int, users: array<int, array{id: string, displayName: string|null, avatar: string|null}>}>
     */
    private function aggregate(array $rows): array
    {
        $byEmoji = [];
        foreach ($rows as $row) {
            $emoji = $row['emoji'];
            if (!isset($byEmoji[$emoji])) {
                $byEmoji[$emoji] = ['emoji' => $emoji, 'count' => 0, 'users' => []];
            }
            $byEmoji[$emoji]['count']++;
            $byEmoji[$emoji]['users'][] = [
                'id' => $row['userId'],
                'displayName' => $row['displayName'] ?? null,
                'avatar' => $row['image'] ?? null,
            ];
        }

        $result = array_values($byEmoji);
        usort($result, static function (array $a, array $b): int {
            return ($b['count'] <=> $a['count']) ?: strcmp($a['emoji'], $b['emoji']);
        });
        return $result;
    }

    private static function normalizeEmoji(string $emoji): string
    {
        $emoji = trim($emoji);
        // Strip Unicode variation selectors: U+FE0E (text) and U+FE0F (emoji).
        return str_replace(["\xEF\xB8\x8E", "\xEF\xB8\x8F"], '', $emoji);
    }
}
