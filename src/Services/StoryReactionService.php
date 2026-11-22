<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\StoryReactionRepository;

/**
 * Applies and lists emoji reactions on stories.
 *
 * Reactions are fixed to a small allowlist, independent of the chat reaction
 * service (the two lists may diverge). Emojis are stored in a normalized form
 * (Unicode variation selectors stripped) so "❤" and "❤️" collapse to the same
 * reaction.
 */
final readonly class StoryReactionService
{
    /**
     * Normalized (variation-selector-free) allowlist. The client mirrors this
     * exact list in `kStoryReactionEmojis`.
     */
    public const array ALLOWED_EMOJIS = ['👍', '❤', '😂', '😮', '😢', '🎉', '🔥', '👏'];

    public function __construct(
        private StoryReactionRepository $reactionRepo,
    ) {}

    /**
     * Set (add or replace) a user's reaction and return the updated summary.
     *
     * @return array<int, array{userId: string, displayName: string|null, avatar: string|null, emoji: string, createdAt: string}>
     */
    public function react(string $storyId, string $userId, string $emoji): array
    {
        $emoji = self::normalizeEmoji($emoji);
        if ($emoji === '' || !in_array($emoji, self::ALLOWED_EMOJIS, true)) {
            throw new \RuntimeException('invalid_emoji');
        }

        $this->reactionRepo->set($storyId, $userId, $emoji);
        return $this->forStory($storyId);
    }

    /**
     * Remove a user's reaction and return the updated summary.
     *
     * @return array<int, array{userId: string, displayName: string|null, avatar: string|null, emoji: string, createdAt: string}>
     */
    public function unreact(string $storyId, string $userId): array
    {
        $this->reactionRepo->remove($storyId, $userId);
        return $this->forStory($storyId);
    }

    /**
     * All reactions of a story, newest first.
     *
     * @return array<int, array{userId: string, displayName: string|null, avatar: string|null, emoji: string, createdAt: string}>
     */
    public function forStory(string $storyId): array
    {
        return array_map(static fn (array $row): array => [
            'userId' => $row['userId'],
            'displayName' => $row['displayName'] ?? null,
            'avatar' => $row['image'] ?? null,
            'emoji' => $row['emoji'],
            'createdAt' => self::stripFractionalSeconds($row['createdAt']),
        ], $this->reactionRepo->findByStoryId($storyId));
    }

    private static function stripFractionalSeconds(string $datetime): string
    {
        $dot = strpos($datetime, '.');
        return $dot !== false ? substr($datetime, 0, $dot) : $datetime;
    }

    private static function normalizeEmoji(string $emoji): string
    {
        $emoji = trim($emoji);
        // Strip Unicode variation selectors: U+FE0E (text) and U+FE0F (emoji).
        return str_replace(["\xEF\xB8\x8E", "\xEF\xB8\x8F"], '', $emoji);
    }
}
