<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * Erzeugt iCalendar-Daten (RFC 5545) aus Kalender-Events der Datenbank.
 *
 * Alle Zeitangaben werden ausschliesslich in UTC serialisiert (Format mit
 * "Z"-Suffix), passend zur UTC-only-Konvention der API. Clients sind fuer
 * die Umrechnung in lokale Zeiten verantwortlich.
 */
final readonly class IcsFactory
{
    private const string PRODID = '-//Sinclear Beyond//CalDAV Server//DE';
    private const string UID_DOMAIN = '@sinclear.de';

    /** @param array<string, mixed> $event */
    public function eventToIcs(array $event, array $participants = []): string
    {
        $vcal = new VCalendar([
            'VERSION' => '2.0',
            'PRODID' => self::PRODID,
            'CALSCALE' => 'GREGORIAN',
        ]);

        $properties = [
            'UID' => $event['id'] . self::UID_DOMAIN,
            'DTSTAMP' => new DateTimeImmutable($event['updatedAt'] ?? $event['createdAt'], new DateTimeZone('UTC')),
            'DTSTART' => new DateTimeImmutable($event['startTime'], new DateTimeZone('UTC')),
            'DTEND' => new DateTimeImmutable($event['endTime'], new DateTimeZone('UTC')),
            'SUMMARY' => (string) $event['title'],
            'CLASS' => $this->classFromVisibility((int) ($event['visibility'] ?? 0)),
        ];

        if (!empty($event['description'])) {
            $properties['DESCRIPTION'] = (string) $event['description'];
        }

        $properties['ORGANIZER'] = 'mailto:' . $event['creatorId'] . self::UID_DOMAIN;

        $vevent = $vcal->add('VEVENT', $properties);

        foreach ($participants as $participant) {
            $vevent->add(
                'ATTENDEE',
                'mailto:' . $participant['id'] . self::UID_DOMAIN,
                ['CN' => (string) $participant['displayName']],
            );
        }

        return $vcal->serialize();
    }

    /**
     * @param array<string, mixed> $event
     * @param list<array<string, mixed>> $participants
     * @return array<string, mixed>
     */
    public function eventToCalendarObject(array $event, array $participants = []): array
    {
        $ics = $this->eventToIcs($event, $participants);
        $lastModified = $event['updatedAt'] ?? $event['createdAt'];

        return [
            'uri' => $event['id'] . '.ics',
            'calendardata' => $ics,
            'lastmodified' => strtotime((string) $lastModified),
            'etag' => '"' . sha1($ics) . '"',
            'size' => strlen($ics),
            'component' => 'vevent',
        ];
    }

    private function classFromVisibility(int $visibility): string
    {
        return match ($visibility) {
            0 => 'PRIVATE',
            2 => 'CONFIDENTIAL',
            default => 'PUBLIC',
        };
    }
}
