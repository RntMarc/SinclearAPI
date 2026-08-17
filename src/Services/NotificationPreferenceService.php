<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\NotificationPreferenceRepository;

final readonly class NotificationPreferenceService
{
    /**
     * Vereinheitlichte Preference-Typen (was der Nutzer in den Settings sieht).
     * Interne Notification-Typen (z.B. standalone_event_user_added) werden
     * diesen Preferences zugeordnet.
     *
     * Diese Liste beschreibt ausschließlich die vom Preferences-Endpoint
     * angebotenen Schlüssel. Interne Notification-Typen werden über
     * TYPE_MAPPING diesen Schlüsseln zugeordnet.
     */
    public const KNOWN_TYPES = [
        'forum_reply',
        'forum_comment',
        'story_post',
        'trip_user_added',
        'trip_user_added_others',
        'trip_event_added',
        'trip_ticket_added',
        'trip_accommodation_added',
        'trip_info_changed',
        'trip_subscription_added',
        'event_user_added',
        'event_user_added_others',
        'event_ticket_added',
        'event_info_changed',
    ];

    /**
     * Mapping: interner Notification-Typ → vereinheitlichter Preference-Typ.
     * Einträge ohne Matching bleiben unverändert.
     *
     * @var array<string, string>
     */
    private const TYPE_MAPPING = [
        'standalone_event_user_added' => 'event_user_added',
        'trip_event_user_added' => 'event_user_added',
        'standalone_event_user_added_others' => 'event_user_added_others',
        'trip_event_user_added_others' => 'event_user_added_others',
        'standalone_event_ticket_added' => 'event_ticket_added',
        'trip_event_ticket_added' => 'event_ticket_added',
        'standalone_event_info_changed' => 'event_info_changed',
        'trip_event_info_changed' => 'event_info_changed',
    ];

    /**
     * Typen, die den State `custom` unterstützen. Der Wert beschreibt die
     * Relation im Notification-`data`, gegen die gefiltert wird, sowie den
     * Schlüssel im customData-JSON.
     *
     * `custom` ist eine DENYLIST: Die im customData enthaltenen IDs werden
     * vom Versand ausgeschlossen. Ohne Einträge wird alles zugestellt.
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
     * Liefert die vollständige Präferenz-Map (alle vereinheitlichten Typen).
     *
     * @return array<string, array{state: string, customAllowed: bool, customData: array<string, mixed>|null}>
     */
    public function getAll(string $userId): array
    {
        $prefs = [];
        $canonicalTypes = [];
        foreach ($this->repo->findByUser($userId) as $row) {
            $type = self::TYPE_MAPPING[$row['type']] ?? $row['type'];
            $isCanonical = $type === $row['type'];

            if (!isset($prefs[$type])
                || ($isCanonical && !($canonicalTypes[$type] ?? false))
                || (!$isCanonical && !($canonicalTypes[$type] ?? false) && $row['state'] === 'disabled')
            ) {
                $prefs[$type] = $row;
                $canonicalTypes[$type] = $isCanonical;
            }
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
     * Der interne Notification-Typ wird über TYPE_MAPPING auf den
     * vereinheitlichten Preference-Typ gemappt.
     *
     * @param array<int, array{relation: string, object: string, identifier: string}>|null $data
     */
    public function shouldSend(string $userId, string $type, ?array $data): bool
    {
        $prefType = self::TYPE_MAPPING[$type] ?? $type;
        $pref = $this->findPreference($userId, $prefType);
        if ($pref === null) {
            return true;
        }

        return match ($pref['state']) {
            'disabled' => false,
            'custom' => $this->matchesCustom($type, $data, $pref['data']),
            default => true,
        };
    }

    /**
     * Liest zunächst die neue gemeinsame Präferenz. Alte, bereits
     * gespeicherte Einzelpräferenzen dienen nur als Fallback, damit die
     * Umstellung des Preferences-Endpoints bestehende Nutzer nicht aktiviert.
     */
    private function findPreference(string $userId, string $preferenceType): ?array
    {
        $preference = $this->repo->findByUserAndType($userId, $preferenceType);
        if ($preference !== null) {
            return $preference;
        }

        $legacyTypes = array_keys(array_filter(
            self::TYPE_MAPPING,
            static fn(string $mappedType): bool => $mappedType === $preferenceType,
        ));

        $fallback = null;
        foreach ($legacyTypes as $legacyType) {
            $legacyPreference = $this->repo->findByUserAndType($userId, $legacyType);
            if ($legacyPreference === null) {
                continue;
            }

            // Bei ehemals getrennten Event-Settings bleibt die restriktivere
            // Einstellung erhalten, bis der Nutzer die gemeinsame Einstellung setzt.
            if ($legacyPreference['state'] === 'disabled') {
                return $legacyPreference;
            }

            $fallback ??= $legacyPreference;
        }

        return $fallback;
    }

    public function customAllowed(string $type): bool
    {
        return isset(self::CUSTOMIZABLE_TYPES[$type]);
    }

    /**
     * Denylist-Auswertung: true (senden), wenn die ID der Filter-Relation
     * NICHT in der customData-Liste enthalten ist. Fehlende/leere Liste
     * oder fehlende Relation bedeuten: nichts ausgeschlossen → senden.
     *
     * @param array<int, array{relation: string, object: string, identifier: string}>|null $data
     * @param array<string, mixed>|null $customData
     */
    private function matchesCustom(string $type, ?array $data, ?array $customData): bool
    {
        $config = self::CUSTOMIZABLE_TYPES[$type] ?? null;
        if ($config === null || !is_array($customData)) {
            return true;
        }

        $identifier = $this->findRelationIdentifier($data, $config['relation']);
        if ($identifier === null) {
            return true;
        }

        $blocked = $customData[$config['dataKey']] ?? null;
        if (!is_array($blocked)) {
            return true;
        }

        return !in_array($identifier, $blocked, true);
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
     * Validierung der Denylist. Erforderlich ist das Objekt mit dem
     * passenden Schlüssel (`forumIds` bzw. `userIds`) als Array aus
     * nicht-leeren Strings. Eine leere Liste ist erlaubt (= nichts
     * ausgeschlossen).
     *
     * @return array<string, mixed>
     */
    private function validateCustomData(string $type, mixed $customData): array
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
