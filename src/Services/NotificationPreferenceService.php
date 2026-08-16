<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\NotificationPreferenceRepository;

final readonly class NotificationPreferenceService
{
    /**
     * Alle bekannten Notification-Typen. Muss mit den Schlüsseln von
     * `NotificationService::CONTENT_TEMPLATES` übereinstimmen.
     */
    public const KNOWN_TYPES = [
        'forum_reply',
        'forum_comment',
        'story_post',
        'trip_user_added',
        'trip_user_added_others',
        'standalone_event_user_added',
        'standalone_event_user_added_others',
        'trip_event_user_added',
        'trip_event_user_added_others',
        'trip_event_added',
        'trip_ticket_added',
        'standalone_event_ticket_added',
        'trip_event_ticket_added',
        'trip_accommodation_added',
        'trip_info_changed',
        'standalone_event_info_changed',
        'trip_event_info_changed',
        'trip_subscription_added',
    ];

    /**
     * Typen, die den State `custom` unterstützen. Der Wert beschreibt die
     * Relation im Notification-`data`, gegen die gefiltert wird, sowie den
     * Schlüssel im customData-JSON.
     *
     * @var array<string, array{relation: string, dataKey: string}>
     */
    private const CUSTOMIZABLE_TYPES = [
        'forum_comment' => ['relation' => 'parent_forum', 'dataKey' => 'forumIds'],
        'forum_reply' => ['relation' => 'parent_forum', 'dataKey' => 'forumIds'],
        'story_post' => ['relation' => 'story_author', 'dataKey' => 'userIds'],
    ];

    private const VALID_STATES = ['enabled', 'disabled', 'custom'];

    public function __construct(
        private NotificationPreferenceRepository $repo,
    ) {}

    /**
     * Liefert die vollständige Präferenz-Map (alle bekannten Typen).
     *
     * @return array<string, array{state: string, customAllowed: bool, customData: array<string, mixed>|null}>
     */
    public function getAll(string $userId): array
    {
        $prefs = [];
        foreach ($this->repo->findByUser($userId) as $row) {
            $prefs[$row['type']] = $row;
        }

        $result = [];
        foreach (self::KNOWN_TYPES as $type) {
            $row = $prefs[$type] ?? null;
            $result[$type] = [
                'state' => $row['state'] ?? 'enabled',
                'customAllowed' => $this->customAllowed($type),
                'customData' => $row['data'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{type: mixed, state: mixed, customData?: mixed}> $preferences
     * @return array<string, array{state: string, customAllowed: bool, customData: array<string, mixed>|null}>
     */
    public function update(string $userId, array $preferences): array
    {
        if ($preferences === []) {
            throw new \InvalidArgumentException('preferences_required');
        }

        foreach ($preferences as $index => $pref) {
            if (!is_array($pref)) {
                throw new \InvalidArgumentException('invalid_preference');
            }

            $type = trim((string) ($pref['type'] ?? ''));
            $state = trim((string) ($pref['state'] ?? ''));

            if ($type === '' || !in_array($type, self::KNOWN_TYPES, true)) {
                throw new \InvalidArgumentException('invalid_type: ' . ($type === '' ? '(empty)' : $type));
            }

            if (!in_array($state, self::VALID_STATES, true)) {
                throw new \InvalidArgumentException('invalid_state: ' . ($state === '' ? '(empty)' : $state));
            }

            if ($state === 'custom') {
                if (!$this->customAllowed($type)) {
                    throw new \InvalidArgumentException("custom_not_allowed: $type");
                }

                $customData = $pref['customData'] ?? null;
                $validated = $this->validateCustomData($type, $customData);
                $this->repo->upsert($userId, $type, $state, $validated);
            } else {
                $this->repo->upsert($userId, $type, $state, null);
            }
        }

        return $this->getAll($userId);
    }

    /**
     * Entscheidet, ob für einen Empfänger eine Benachrichtigung gesendet
     * werden soll. Kein Präferenz-Eintrag bedeutet Standard: enabled.
     *
     * @param array<int, array{relation: string, object: string, identifier: string}>|null $data
     */
    public function shouldSend(string $userId, string $type, ?array $data): bool
    {
        $pref = $this->repo->findByUserAndType($userId, $type);
        if ($pref === null) {
            return true;
        }

        return match ($pref['state']) {
            'disabled' => false,
            'custom' => $this->matchesCustom($type, $data, $pref['data']),
            default => true,
        };
    }

    public function customAllowed(string $type): bool
    {
        return isset(self::CUSTOMIZABLE_TYPES[$type]);
    }

    /**
     * @param array<int, array{relation: string, object: string, identifier: string}>|null $data
     * @param array<string, mixed>|null $customData
     */
    private function matchesCustom(string $type, ?array $data, ?array $customData): bool
    {
        $config = self::CUSTOMIZABLE_TYPES[$type] ?? null;
        if ($config === null || !is_array($customData)) {
            return false;
        }

        $identifier = $this->findRelationIdentifier($data, $config['relation']);
        if ($identifier === null) {
            return false;
        }

        $allowed = $customData[$config['dataKey']] ?? null;

        return is_array($allowed) && in_array($identifier, $allowed, true);
    }

    /**
     * @param array<int, array{relation: string, object: string, identifier: string}>|null $data
     */
    private function findRelationIdentifier(?array $data, string $relation): ?string
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $entry) {
            if (is_array($entry) && ($entry['relation'] ?? null) === $relation) {
                return (string) ($entry['identifier'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateCustomData(string $type, mixed $customData): ?array
    {
        $config = self::CUSTOMIZABLE_TYPES[$type];
        $dataKey = $config['dataKey'];

        if (!is_array($customData)) {
            throw new \InvalidArgumentException("custom_data_required: $type");
        }

        if (!isset($customData[$dataKey]) || !is_array($customData[$dataKey])) {
            throw new \InvalidArgumentException("custom_data_invalid: $type");
        }

        $values = [];
        foreach ($customData[$dataKey] as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException("custom_data_invalid: $type");
            }
            $values[] = trim($value);
        }

        return [$dataKey => array_values(array_unique($values))];
    }
}
