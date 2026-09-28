<?php

namespace Sinclear\Api\Support;

/**
 * Gemeinsame Eingabe-Normalisierung fuer Reisen/Events (Admin + User).
 *
 * Kuemmert sich um die zeitzonenbewussten Timing-Felder gemaess
 * AGENTS.md: getaktet sind RFC 3339 mit Offset, ganztägig zivile Tage.
 */
final class TravelInput
{
    /**
     * @param array<string, mixed> $body
     */
    public static function hasTimingFields(array $body): bool
    {
        foreach (['allDay', 'timezone', 'startAt', 'endAt', 'startDate', 'endDate'] as $field) {
            if (array_key_exists($field, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Baut die kanonischen Timing-Felder fuer Reise-/Event-Schreiboperationen.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException mit einem API-Fehlercode als Message
     */
    public static function timingFromBody(array $body, bool $defaultAllDay = false): array
    {
        $allDay = array_key_exists('allDay', $body) ? (bool) $body['allDay'] : $defaultAllDay;

        try {
            $timezone = DateTimeValue::normalizeTimeZone(
                isset($body['timezone']) && is_string($body['timezone']) ? $body['timezone'] : null,
            );
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('invalid_timezone');
        }

        if ($allDay) {
            $startDate = trim((string) ($body['startDate'] ?? ''));
            $endDate = trim((string) ($body['endDate'] ?? ''));
            if ($startDate === '' || $endDate === '') {
                throw new \InvalidArgumentException('date_required');
            }
            try {
                $start = DateTimeValue::parseDate($startDate);
                $end = DateTimeValue::parseDate($endDate);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('invalid_date');
            }
            if ($end < $start) {
                throw new \InvalidArgumentException('invalid_time_range');
            }

            return [
                'allDay' => 1,
                'timezone' => $timezone,
                'startDate' => DateTimeValue::formatDate($start),
                'endDate' => DateTimeValue::formatDate($end),
                'startAt' => null,
                'endAt' => null,
            ];
        }

        $startAt = trim((string) ($body['startAt'] ?? ''));
        $endAt = trim((string) ($body['endAt'] ?? ''));
        if ($startAt === '' || $endAt === '') {
            throw new \InvalidArgumentException('time_required');
        }
        try {
            $start = DateTimeValue::parseInstant($startAt);
            $end = DateTimeValue::parseInstant($endAt);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('invalid_datetime');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('invalid_time_range');
        }

        return [
            'allDay' => 0,
            'timezone' => $timezone,
            'startAt' => DateTimeValue::toDatabase($start),
            'endAt' => DateTimeValue::toDatabase($end),
            'startDate' => null,
            'endDate' => null,
        ];
    }

    /**
     * Trimmter String oder null, wenn der Wert fehlt/leer/kein String ist.
     *
     * @param array<string, mixed> $body
     */
    public static function nullableString(array $body, string $key): ?string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            return null;
        }

        $value = trim($body[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function nullableFloat(array $body, string $key): ?float
    {
        if (!isset($body[$key]) || $body[$key] === '') {
            return null;
        }

        return (float) $body[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function nullableInt(array $body, string $key): ?int
    {
        if (!isset($body[$key]) || $body[$key] === '') {
            return null;
        }

        return (int) $body[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function boolFlag(array $body, string $key): string
    {
        return !empty($body[$key]) ? '1' : '0';
    }

    public static function isValidImageData(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        $header = substr($decoded, 0, 4);
        return str_starts_with($header, "\xFF\xD8\xFF")
            || str_starts_with($header, "\x89PNG")
            || ($header === 'RIFF' && substr($decoded, 8, 4) === 'WEBP');
    }

    /**
     * @param array<string, mixed> $oldTrip
     * @param array<string, mixed> $newData
     * @return list<string>
     */
    public static function detectTripChanges(array $oldTrip, array $newData): array
    {
        $fieldLabels = [
            'name' => 'Name',
            'description' => 'Beschreibung',
            'startAt' => 'Startzeitpunkt',
            'endAt' => 'Endzeitpunkt',
            'startDate' => 'Startdatum',
            'endDate' => 'Enddatum',
            'timezone' => 'Zeitzone',
            'allDay' => 'Ganztägig',
            'hastickets' => 'Ticket-Status',
            'ticket' => 'Ticket-Informationen',
            'ticketUrl' => 'Ticket-URL',
        ];

        return self::detectChanges($oldTrip, $newData, $fieldLabels);
    }

    /**
     * @param array<string, mixed> $oldEvent
     * @param array<string, mixed> $newData
     * @return list<string>
     */
    public static function detectEventChanges(array $oldEvent, array $newData): array
    {
        $fieldLabels = [
            'name' => 'Name',
            'description' => 'Beschreibung',
            'startAt' => 'Startzeitpunkt',
            'endAt' => 'Endzeitpunkt',
            'startDate' => 'Startdatum',
            'endDate' => 'Enddatum',
            'timezone' => 'Zeitzone',
            'allDay' => 'Ganztägig',
            'hastickets' => 'Ticket-Status',
            'ticket' => 'Ticket-Informationen',
            'ticketUrl' => 'Ticket-URL',
            'url' => 'URL',
            'image' => 'Bild',
            'organizer' => 'Veranstalter',
            'address' => 'Adresse',
            'citySlug' => 'City-Slug',
        ];

        return self::detectChanges($oldEvent, $newData, $fieldLabels);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $newData
     * @param array<string, string> $fieldLabels
     * @return list<string>
     */
    private static function detectChanges(array $old, array $newData, array $fieldLabels): array
    {
        $changed = [];
        foreach ($newData as $field => $newValue) {
            if (!isset($fieldLabels[$field])) {
                continue;
            }
            $oldValue = $old[$field] ?? null;
            if ($oldValue != $newValue) {
                $changed[] = $fieldLabels[$field];
            }
        }

        return $changed;
    }
}
