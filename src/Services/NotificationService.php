<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;

final readonly class NotificationService
{
    private const CONTENT_TEMPLATES = [
        'forum_reply' => [
            'title' => 'Neue Antwort auf deinen Kommentar',
            'text' => 'Jemand hat auf deinen Kommentar geantwortet.',
        ],
        'forum_comment' => [
            'title' => 'Neuer Kommentar zu deinem Beitrag',
            'text' => 'Jemand hat deinen Beitrag kommentiert.',
        ],
        'story_post' => [
            'title' => 'Neue Story',
            'text' => 'Jemand hat eine neue Story veröffentlicht.',
        ],
        'trip_user_added' => [
            'title' => 'Du wurdest zu einer Reise hinzugefügt',
            'text' => '',
        ],
        'trip_user_added_others' => [
            'title' => 'Neuer Teilnehmer auf der Reise',
            'text' => '',
        ],
        'standalone_event_user_added' => [
            'title' => 'Du wurdest zu einem Event hinzugefügt',
            'text' => '',
        ],
        'standalone_event_user_added_others' => [
            'title' => 'Neuer Teilnehmer beim Event',
            'text' => '',
        ],
        'trip_event_user_added' => [
            'title' => 'Du wurdest zu einem Event hinzugefügt',
            'text' => '',
        ],
        'trip_event_user_added_others' => [
            'title' => 'Neuer Teilnehmer beim Event',
            'text' => '',
        ],
        'trip_event_added' => [
            'title' => 'Neues Event auf der Reise',
            'text' => '',
        ],
        'trip_ticket_added' => [
            'title' => 'Neues Ticket für die Reise',
            'text' => '',
        ],
        'standalone_event_ticket_added' => [
            'title' => 'Neues Ticket für das Event',
            'text' => '',
        ],
        'trip_event_ticket_added' => [
            'title' => 'Neues Ticket für das Event',
            'text' => '',
        ],
        'trip_accommodation_added' => [
            'title' => 'Hotel-Zuweisung',
            'text' => '',
        ],
        'trip_info_changed' => [
            'title' => 'Reise-Informationen geändert',
            'text' => '',
        ],
        'standalone_event_info_changed' => [
            'title' => 'Event-Informationen geändert',
            'text' => '',
        ],
        'trip_event_info_changed' => [
            'title' => 'Event-Informationen geändert',
            'text' => '',
        ],
        'trip_subscription_added' => [
            'title' => 'Neues Abo verknüpft',
            'text' => '',
        ],
        'direct_message' => [
            'title' => 'Neue Nachricht',
            'text' => '',
        ],
    ];

    public function __construct(
        private NotificationRepository $notificationRepo,
        private PushSubscriptionRepository $pushSubRepo,
        private NotificationPreferenceService $preferenceService,
        private ?WebPush $webPush = null,
        private ?Client $httpClient = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function create(string $userId, string $type, string $title, string $body, ?array $data = null, bool $respectPreferences = true, ?string $dedupeKey = null, bool $suppressPush = false): ?string
    {
        $type = trim($type);
        $title = trim($title);
        $body = trim($body);

        if ($type === '') {
            throw new \InvalidArgumentException('type is required');
        }

        if ($respectPreferences && !$this->preferenceService->shouldSend($userId, $type, $data)) {
            return null;
        }

        $data = $this->normalizeData($type, $data);

        $template = self::CONTENT_TEMPLATES[$type] ?? [];
        $generatedTitle = $template['title'] ?? '';
        $generatedText = $template['text'] ?? '';
        $title = $title !== '' ? $title : $generatedTitle;
        $text = $body !== '' ? $body : $generatedText;

        $recordData = [
            'userId' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $text,
            'data' => $data,
            'dedupeKey' => $dedupeKey,
        ];

        // Coalesced upsert when dedupeKey is provided
        if ($dedupeKey !== null) {
            $existing = $this->notificationRepo->findUnreadByDedupeKey($userId, $dedupeKey);
            $record = $this->notificationRepo->coalescedUpsert($recordData, $existing);
        } else {
            $record = $this->notificationRepo->create($recordData);
        }

        $notification = [
            'id' => $record['id'],
            'type' => $type,
            'title' => $title,
            'text' => $text,
            'data' => $data,
            'createdAt' => $record['createdAt'],
        ];

        if (!$suppressPush) {
            $this->sendWebPush($userId, $notification);
            $this->sendUnifiedPush($userId, $notification);
        }

        return $record['id'];
    }


    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>|null
     */
    private function normalizeData(string $type, ?array $data): ?array
    {
        return match ($type) {
            'forum_reply' => $this->normalizeForumReplyData($data),
            'forum_comment' => $this->normalizeForumCommentData($data),
            'story_post' => $this->normalizeStoryPostData($data),
            'trip_user_added', 'trip_user_added_others' => $this->normalizeTripUserAddedData($data),
            'standalone_event_user_added', 'standalone_event_user_added_others' => $this->normalizeStandaloneEventUserAddedData($data),
            'trip_event_user_added', 'trip_event_user_added_others' => $this->normalizeTripEventUserAddedData($data),
            'trip_event_added' => $this->normalizeTripEventAddedData($data),
            'trip_ticket_added' => $this->normalizeTripTicketAddedData($data),
            'standalone_event_ticket_added' => $this->normalizeStandaloneEventTicketAddedData($data),
            'trip_event_ticket_added' => $this->normalizeTripEventTicketAddedData($data),
            'trip_accommodation_added' => $this->normalizeTripAccommodationAddedData($data),
            'trip_info_changed' => $this->normalizeTripInfoChangedData($data),
            'standalone_event_info_changed' => $this->normalizeStandaloneEventInfoChangedData($data),
            'trip_event_info_changed' => $this->normalizeTripEventInfoChangedData($data),
            'trip_subscription_added' => $this->normalizeTripSubscriptionAddedData($data),
            'direct_message' => $this->normalizeDirectMessageData($data),
            default => throw new \InvalidArgumentException('unsupported notification type'),
        };
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeForumReplyData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('forum_reply data is required');
        }

        $requiredRelations = [
            'reply_author' => 'User',
            'comment_author' => 'User',
            'post_author' => 'User',
            'parent_comment' => 'ForumPostComment',
            'parent_post' => 'ForumPost',
            'parent_forum' => 'Forum',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('forum_reply data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('forum_reply data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('forum_reply data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('forum_reply data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeForumCommentData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('forum_comment data is required');
        }

        $requiredRelations = [
            'comment_author' => 'User',
            'post_author' => 'User',
            'parent_post' => 'ForumPost',
            'parent_forum' => 'Forum',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('forum_comment data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('forum_comment data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('forum_comment data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('forum_comment data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeStoryPostData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('story_post data is required');
        }

        $requiredRelations = [
            'story_author' => 'User',
            'story' => 'Story',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('story_post data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('story_post data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('story_post data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('story_post data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripUserAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_user_added data is required');
        }

        $requiredRelations = [
            'added_user' => 'User',
            'trip' => 'Trip',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_user_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_user_added data entries require relation, object, and identifier');
            }

            if ($relation === 'added_by') {
                $normalized['added_by'] = [
                    'relation' => 'added_by',
                    'object' => 'User',
                    'identifier' => $identifier,
                ];
                continue;
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_user_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_user_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeStandaloneEventUserAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('standalone_event_user_added data is required');
        }

        $requiredRelations = [
            'added_user' => 'User',
            'event' => 'Event',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('standalone_event_user_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('standalone_event_user_added data entries require relation, object, and identifier');
            }

            if ($relation === 'added_by') {
                $normalized['added_by'] = [
                    'relation' => 'added_by',
                    'object' => 'User',
                    'identifier' => $identifier,
                ];
                continue;
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('standalone_event_user_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('standalone_event_user_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripEventUserAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_event_user_added data is required');
        }

        $requiredRelations = [
            'added_user' => 'User',
            'event' => 'Event',
            'trip' => 'Trip',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_event_user_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_event_user_added data entries require relation, object, and identifier');
            }

            if ($relation === 'added_by') {
                $normalized['added_by'] = [
                    'relation' => 'added_by',
                    'object' => 'User',
                    'identifier' => $identifier,
                ];
                continue;
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_event_user_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_event_user_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripEventAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_event_added data is required');
        }

        $requiredRelations = [
            'event' => 'Event',
            'trip' => 'Trip',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_event_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_event_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_event_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_event_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripTicketAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_ticket_added data is required');
        }

        $requiredRelations = [
            'ticket' => 'Ticket',
            'trip' => 'Trip',
            'uploaded_by' => 'User',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_ticket_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_ticket_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_ticket_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_ticket_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeStandaloneEventTicketAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('standalone_event_ticket_added data is required');
        }

        $requiredRelations = [
            'ticket' => 'Ticket',
            'event' => 'Event',
            'uploaded_by' => 'User',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('standalone_event_ticket_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('standalone_event_ticket_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('standalone_event_ticket_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('standalone_event_ticket_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripEventTicketAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_event_ticket_added data is required');
        }

        $requiredRelations = [
            'ticket' => 'Ticket',
            'event' => 'Event',
            'uploaded_by' => 'User',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_event_ticket_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_event_ticket_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_event_ticket_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_event_ticket_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripAccommodationAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_accommodation_added data is required');
        }

        $requiredRelations = [
            'accommodation' => 'Accommodation',
            'trip' => 'Trip',
            'user' => 'User',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_accommodation_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_accommodation_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_accommodation_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_accommodation_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripInfoChangedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_info_changed data is required');
        }

        $requiredRelations = [
            'trip' => 'Trip',
            'changed_by' => 'User',
            'changed_fields' => 'FieldList',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_info_changed data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_info_changed data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_info_changed data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_info_changed data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeStandaloneEventInfoChangedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('standalone_event_info_changed data is required');
        }

        $requiredRelations = [
            'event' => 'Event',
            'changed_by' => 'User',
            'changed_fields' => 'FieldList',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('standalone_event_info_changed data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('standalone_event_info_changed data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('standalone_event_info_changed data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('standalone_event_info_changed data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripEventInfoChangedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_event_info_changed data is required');
        }

        $requiredRelations = [
            'event' => 'Event',
            'trip' => 'Trip',
            'changed_by' => 'User',
            'changed_fields' => 'FieldList',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_event_info_changed data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_event_info_changed data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_event_info_changed data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_event_info_changed data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeTripSubscriptionAddedData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('trip_subscription_added data is required');
        }

        $requiredRelations = [
            'subscription' => 'Subscription',
            'trip' => 'Trip',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('trip_subscription_added data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('trip_subscription_added data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('trip_subscription_added data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('trip_subscription_added data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{relation: string, object: string, identifier: string}>
     */
    private function normalizeDirectMessageData(?array $data): array
    {
        if ($data === null || $data === []) {
            throw new \InvalidArgumentException('direct_message data is required');
        }

        $requiredRelations = [
            'sender' => 'User',
            'conversation' => 'ChatConversation',
            'message' => 'DirectMessage',
        ];

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('direct_message data entries must be objects');
            }

            $relation = trim((string) ($entry['relation'] ?? ''));
            $object = trim((string) ($entry['object'] ?? ''));
            $identifier = trim((string) ($entry['identifier'] ?? ''));

            if ($relation === '' || $object === '' || $identifier === '') {
                throw new \InvalidArgumentException('direct_message data entries require relation, object, and identifier');
            }

            if (!isset($requiredRelations[$relation]) || $requiredRelations[$relation] !== $object) {
                throw new \InvalidArgumentException('direct_message data contains an unsupported relation/object pair');
            }

            $normalized[$relation] = [
                'relation' => $relation,
                'object' => $object,
                'identifier' => $identifier,
            ];
        }

        foreach ($requiredRelations as $relation => $_object) {
            if (!isset($normalized[$relation])) {
                throw new \InvalidArgumentException('direct_message data is missing relation: ' . $relation);
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array[]
     */
    public function getUnread(string $userId, ?string $since = null): array
    {
        return $this->notificationRepo->getUnread($userId, $since);
    }

    public function markRead(string $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $cleaned = array_values(array_filter(array_map('trim', $ids), fn(string $id): bool => $id !== ''));
        if ($cleaned === []) {
            return;
        }

        $this->notificationRepo->markRead($userId, $cleaned);
    }

    private function sendWebPush(string $userId, array $notification): void
    {
        if ($this->webPush === null) {
            return;
        }

        $subscriptions = $this->pushSubRepo->findByUserId($userId, 'webpush');
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode($notification, JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            try {
                $webPushSubscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => [
                        'p256dh' => $sub['p256dh'],
                        'auth' => $sub['auth'],
                    ],
                ]);

                $report = $this->webPush->sendOneNotification($webPushSubscription, $payload);

                if ($report->isSubscriptionExpired()) {
                    $this->pushSubRepo->deleteById($sub['id']);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Web Push delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendUnifiedPush(string $userId, array $notification): void
    {
        if ($this->httpClient === null) {
            return;
        }

        $subscriptions = $this->pushSubRepo->findByUserId($userId, 'unifiedpush');
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode($notification, JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            try {
                $this->httpClient->post($sub['endpoint'], [
                    'body' => $payload,
                    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'timeout' => 10,
                ]);
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode();

                if ($statusCode === 410) {
                    $this->pushSubRepo->deleteById($sub['id']);
                    continue;
                }

                $this->logger?->warning('UnifiedPush delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'status' => $statusCode,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $this->logger?->warning('UnifiedPush delivery failed', [
                    'userId' => $userId,
                    'endpoint' => $sub['endpoint'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Proactively removes expired push subscriptions.
     *
     * Currently uses reactive cleanup (on 410/404 during delivery) as the
     * primary mechanism. This method exists for spec compliance and can be
     * extended when a `failedAt`/`lastError` column is added to
     * `PushSubscription` to track delivery failures without sending test
     * notifications.
     *
     * @return int Number of subscriptions removed
     */
    public function cleanExpiredSubscriptions(): int
    {
        // Reactive cleanup happens in sendWebPush()/sendUnifiedPush()
        // when a 410/404 is received. A proactive sweep would require
        // tracking failed deliveries (e.g. failedAt column), which is not
        // currently implemented. Return 0 to indicate no proactive action.
        return 0;
    }
}
