<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\NotificationRepository;

/**
 * Bündelt ungelesene Benachrichtigungen zu einer Kurz-Zusammenfassung für die
 * LaMetric-Time-Notification-App. Es werden bewusst keine Nachrichteninhalte
 * ausgegeben, sondern nur Zähler je Kategorie.
 */
final readonly class LaMetricSummaryService
{
    /** @var list<array{types: list<string>, singular: string, plural: string}> */
    private const BUCKETS = [
        [
            'types' => ['direct_message'],
            'singular' => '1 neuer Chat',
            'plural' => '%d neue Chats',
        ],
        [
            'types' => ['forum_reply', 'forum_comment', 'forum_post', 'forum_upvote'],
            'singular' => '1 neuer Foren-Beitrag',
            'plural' => '%d neue Foren-Beiträge',
        ],
        [
            'types' => [
                'trip_user_added',
                'trip_user_added_others',
                'trip_ticket_added',
                'trip_accommodation_added',
                'trip_info_changed',
                'trip_subscription_added',
            ],
            'singular' => '1 neue Reise',
            'plural' => '%d neue Reisen',
        ],
        [
            'types' => [
                'standalone_event_user_added',
                'standalone_event_user_added_others',
                'trip_event_user_added',
                'trip_event_user_added_others',
                'trip_event_added',
                'standalone_event_ticket_added',
                'trip_event_ticket_added',
                'standalone_event_info_changed',
                'trip_event_info_changed',
            ],
            'singular' => '1 neues Event',
            'plural' => '%d neue Events',
        ],
        [
            'types' => ['story_post'],
            'singular' => '1 neue Story',
            'plural' => '%d neue Stories',
        ],
        [
            'types' => [],
            'singular' => '1 neue Benachrichtigung',
            'plural' => '%d neue Benachrichtigungen',
        ],
    ];

    private const string EMPTY_TEXT = 'Alles gelesen';

    public function __construct(
        private NotificationRepository $notificationRepo,
    ) {}

    /**
     * @return array{frames: list<array{text: string}>}
     */
    public function buildSummary(string $userId): array
    {
        $counts = $this->notificationRepo->countUnreadGroupedByType($userId);

        return [
            'frames' => [
                ['text' => self::buildText($counts)],
            ],
        ];
    }

    /**
     * @param array<string, int> $counts Typ => Anzahl ungelesener Benachrichtigungen
     */
    public static function buildText(array $counts): string
    {
        $bucketCounts = array_fill(0, count(self::BUCKETS), 0);
        $typeToBucket = self::typeToBucketMap();
        $fallbackIndex = array_key_last(self::BUCKETS);

        foreach ($counts as $type => $count) {
            if ($count <= 0) {
                continue;
            }

            $index = $typeToBucket[$type] ?? $fallbackIndex;
            $bucketCounts[$index] += $count;
        }

        $phrases = [];
        foreach (self::BUCKETS as $index => $bucket) {
            $count = $bucketCounts[$index];
            if ($count <= 0) {
                continue;
            }

            $phrases[] = $count === 1
                ? $bucket['singular']
                : sprintf($bucket['plural'], $count);
        }

        if ($phrases === []) {
            return self::EMPTY_TEXT;
        }

        return self::join($phrases);
    }

    /**
     * @return array<string, int>
     */
    private static function typeToBucketMap(): array
    {
        $map = [];
        foreach (self::BUCKETS as $index => $bucket) {
            foreach ($bucket['types'] as $type) {
                $map[$type] = $index;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $phrases
     */
    private static function join(array $phrases): string
    {
        if (count($phrases) === 1) {
            return $phrases[0];
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases) . ' und ' . $last;
    }
}
