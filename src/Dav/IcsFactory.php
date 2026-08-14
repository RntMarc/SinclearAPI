<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * Erzeugt iCalendar-Daten (RFC 5545) aus Kalender-Events der Datenbank
 * sowie aus Feed-Items (CalendarFeedService).
 *
 * Alle Zeitangaben werden ausschliesslich in UTC serialisiert (Format mit
 * "Z"-Suffix), passend zur UTC-only-Konvention der API. Clients sind fuer
 * die Umrechnung in lokale Zeiten verantwortlich.
 */
final readonly class IcsFactory
{
    private const string PRODID = '-//Sinclear Beyond//CalDAV Server//DE';
    private const string UID_DOMAIN = '@sinclear.de';

    // ─── CalendarEvent (Legacy) ────────────────────────────────────────

    /** @param array<string, mixed> $event */
    public function eventToIcs(array $event, array $participants = []): string
    {
        $vcal = $this->createCalendar();

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

    // ─── Feed-Items (CalendarFeedService) ──────────────────────────────

    /**
     * Konvertiert ein Feed-Item (CalendarFeedService) in ein CalDAV-
     * CalendarObject-Array. Dispatcht je nach type auf die passende Methode.
     *
     * @param array<string, mixed> $item Feed-Item mit type, id, title, startTime, endTime, allDay, detail
     * @return array<string, mixed> CalDAV calendar-object array
     */
    public function feedItemToCalendarObject(array $item): array
    {
        $ics = match ($item['type']) {
            'calendar_event' => $this->calendarEventFromFeed($item),
            'travel_event' => $this->travelEventToIcs($item),
            'trip' => $this->tripToIcs($item),
            'pt_journey' => $this->ptJourneyToIcs($item),
            'birthday' => $this->birthdayToIcs($item),
            default => $this->calendarEventFromFeed($item),
        };

        $lastModified = $item['detail']['updatedAt'] ?? $item['detail']['createdAt'] ?? $item['startTime'];

        return [
            'uri' => $item['id'] . '.ics',
            'calendardata' => $ics,
            'lastmodified' => is_string($lastModified) ? strtotime($lastModified) : (int) $lastModified,
            'etag' => '"' . sha1($ics) . '"',
            'size' => strlen($ics),
            'component' => 'vevent',
        ];
    }

    /**
     * Liefert eine eindeutige UID fuer ein Feed-Item.
     * Feed-IDs sind bereits typ-prefixiert (z.B. "trip-{uuid}").
     */
    public function feedItemUid(array $item): string
    {
        return $item['id'] . self::UID_DOMAIN;
    }

    // ─── Private: Feed-Typen ───────────────────────────────────────────

    /** @param array<string, mixed> $item */
    private function calendarEventFromFeed(array $item): string
    {
        $vcal = $this->createCalendar();
        $detail = $item['detail'];

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable(
                $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startTime'],
                new DateTimeZone('UTC'),
            ),
            'DTSTART' => new DateTimeImmutable($item['startTime'], new DateTimeZone('UTC')),
            'DTEND' => new DateTimeImmutable($item['endTime'], new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
            'CLASS' => $this->classFromVisibility((int) ($detail['visibility'] ?? 0)),
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $properties['ORGANIZER'] = 'mailto:' . $detail['creatorId'] . self::UID_DOMAIN;

        $vevent = $vcal->add('VEVENT', $properties);

        $participants = $detail['participants'] ?? [];
        foreach ($participants as $participant) {
            $vevent->add(
                'ATTENDEE',
                'mailto:' . $participant['id'] . self::UID_DOMAIN,
                ['CN' => (string) $participant['displayName']],
            );
        }

        return $vcal->serialize();
    }

    /** @param array<string, mixed> $item */
    private function travelEventToIcs(array $item): string
    {
        $vcal = $this->createCalendar();
        $detail = $item['detail'];

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'DTSTART' => new DateTimeImmutable($item['startTime'], new DateTimeZone('UTC')),
            'DTEND' => new DateTimeImmutable($item['endTime'], new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $vevent = $vcal->add('VEVENT', $properties);

        $participants = $detail['participants'] ?? [];
        foreach ($participants as $participant) {
            $vevent->add(
                'ATTENDEE',
                'mailto:' . $participant['id'] . self::UID_DOMAIN,
                ['CN' => (string) $participant['displayName']],
            );
        }

        return $vcal->serialize();
    }

    /** @param array<string, mixed> $item */
    private function tripToIcs(array $item): string
    {
        $vcal = $this->createCalendar();
        $detail = $item['detail'];

        $startDt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($item['startTime'], 0, 10));
        $endDt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($item['endTime'], 0, 10));

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'DTSTART' => $startDt,
            'DTEND' => $endDt->modify('+1 day'),
            'SUMMARY' => (string) $item['title'],
            'TRANSP' => 'TRANSPARENT',
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $vcal->add('VEVENT', $properties);

        return $vcal->serialize();
    }

    /** @param array<string, mixed> $item */
    private function ptJourneyToIcs(array $item): string
    {
        $vcal = $this->createCalendar();
        $detail = $item['detail'];

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'DTSTART' => new DateTimeImmutable($item['startTime'], new DateTimeZone('UTC')),
            'DTEND' => new DateTimeImmutable($item['endTime'], new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
        ];

        $description = $this->formatPtDescription($detail);
        if ($description !== '') {
            $properties['DESCRIPTION'] = $description;
        }

        $vcal->add('VEVENT', $properties);

        return $vcal->serialize();
    }

    /** @param array<string, mixed> $item */
    private function birthdayToIcs(array $item): string
    {
        $vcal = $this->createCalendar();
        $detail = $item['detail'];

        $dateStr = $detail['occurrenceDate'] ?? substr($item['startTime'], 0, 10);
        $startDt = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
        $endDt = $startDt->modify('+1 day');

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'DTSTART' => $startDt,
            'DTEND' => $endDt,
            'SUMMARY' => (string) $item['title'],
            'TRANSP' => 'TRANSPARENT',
            'RRULE' => 'FREQ=YEARLY',
        ];

        $vcal->add('VEVENT', $properties);

        return $vcal->serialize();
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function createCalendar(): VCalendar
    {
        return new VCalendar([
            'VERSION' => '2.0',
            'PRODID' => self::PRODID,
            'CALSCALE' => 'GREGORIAN',
        ]);
    }

    private function classFromVisibility(int $visibility): string
    {
        return match ($visibility) {
            0 => 'PRIVATE',
            2 => 'CONFIDENTIAL',
            default => 'PUBLIC',
        };
    }

    /**
     * Formatert PtJourney-Legs als lesbaren Text fuer die DESCRIPTION.
     *
     * @param array<string, mixed> $detail
     */
    private function formatPtDescription(array $detail): string
    {
        $lines = [];
        $lines[] = sprintf('Von %s nach %s', $detail['fromStationName'] ?? '', $detail['toStationName'] ?? '');
        $lines[] = sprintf('Abfahrt: %s | Ankunft: %s', $detail['departureTime'] ?? '', $detail['arrivalTime'] ?? '');
        $lines[] = sprintf('Dauer: %d min | Umstiege: %d', (int) ($detail['duration'] ?? 0) / 60, (int) ($detail['transfers'] ?? 0));

        $legs = $detail['legs'] ?? [];
        if ($legs !== []) {
            $lines[] = '';
            $lines[] = 'Verbindungsabschnitte:';
            foreach ($legs as $leg) {
                $lineName = $leg['lineName'] ?? $leg['mode'] ?? '';
                $from = $leg['fromStationName'] ?? '';
                $to = $leg['toStationName'] ?? '';
                $dep = $leg['plannedDeparture'] ?? '';
                $arr = $leg['plannedArrival'] ?? '';
                $lines[] = sprintf('  %s: %s → %s (%s – %s)', $lineName, $from, $to, $dep, $arr);
            }
        }

        return implode("\n", $lines);
    }
}
