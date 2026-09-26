<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sinclear\Api\Support\DateTimeValue;

/**
 * Erzeugt iCalendar-Daten (RFC 5545) aus Kalender-Events der Datenbank
 * sowie aus Feed-Items (CalendarFeedService).
 *
 * Getaktete Eintraege sind absolute Zeitpunkte und werden in UTC (Format mit
 * "Z"-Suffix) serialisiert, damit Kalender-Clients sie unabhaengig von der
 * Geraetezeitzone korrekt sortieren. Die gemeinte Zeitzone des Eintrags wird
 * zusaetzlich als X-WR-TIMEZONE am VCALENDAR ausgewiesen. Ganztägige Events
 * (allDay=true) werden als VALUE=DATE serialisiert mit exklusivem DTEND
 * (End-Tag + 1 Tag).
 */
final readonly class IcsFactory
{
    private const string PRODID = '-//Sinclear Beyond//CalDAV Server//DE';
    private const string UID_DOMAIN = '@sinclear.de';

    // ─── CalendarEvent (Legacy) ────────────────────────────────────────

    /** @param array<string, mixed> $event */
    public function eventToIcs(array $event, array $participants = []): string
    {
        $allDay = (bool) ($event['allDay'] ?? 0);
        $vcal = $this->createCalendar($event['timezone'] ?? null);
        $dtStamp = $event['updatedAt'] ?? $event['createdAt'];

        $properties = [
            'UID' => $event['id'] . self::UID_DOMAIN,
            'DTSTAMP' => new DateTimeImmutable($dtStamp, new DateTimeZone('UTC')),
            'SUMMARY' => (string) $event['title'],
            'CLASS' => $this->classFromVisibility((int) ($event['visibility'] ?? 0)),
        ];

        if (!empty($event['description'])) {
            $properties['DESCRIPTION'] = (string) $event['description'];
        }

        $properties['ORGANIZER'] = 'mailto:' . $event['creatorId'] . self::UID_DOMAIN;

        $vevent = $vcal->add('VEVENT', $properties);
        $this->addDateRange($vevent, $event, $allDay);

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
     * @param array<string, mixed> $item Feed-Item mit type, id, title, allDay, timezone, startAt/endAt bzw. startDate/endDate, detail
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

        $lastModified = $item['detail']['updatedAt'] ?? $item['detail']['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? null;

        return [
            'uri' => $item['id'] . '.ics',
            'calendardata' => $ics,
            'lastmodified' => is_string($lastModified) ? strtotime($lastModified) : (int) ($lastModified ?? 0),
            'etag' => '"' . sha1($ics) . '"',
            'size' => strlen($ics),
            'component' => 'vevent',
        ];
    }

    /**
     * Liefert eine eindeutige UID fuer ein Feed-Item.
     */
    public function feedItemUid(array $item): string
    {
        return $item['id'] . self::UID_DOMAIN;
    }

    // ─── Private: Feed-Typen ───────────────────────────────────────────

    /** @param array<string, mixed> $item */
    private function calendarEventFromFeed(array $item): string
    {
        $detail = $item['detail'];
        $allDay = (bool) ($item['allDay'] ?? 0);
        $vcal = $this->createCalendar($item['timezone'] ?? null);
        $dtStamp = $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? 'now';

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable($dtStamp, new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
            'CLASS' => $this->classFromVisibility((int) ($detail['visibility'] ?? 0)),
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $properties['ORGANIZER'] = 'mailto:' . $detail['creatorId'] . self::UID_DOMAIN;

        $vevent = $vcal->add('VEVENT', $properties);
        $this->addDateRange($vevent, $item, $allDay);

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
        $detail = $item['detail'];
        $allDay = (bool) ($item['allDay'] ?? 0);
        $vcal = $this->createCalendar($item['timezone'] ?? null);
        $dtStamp = $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? 'now';

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable($dtStamp, new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $vevent = $vcal->add('VEVENT', $properties);
        $this->addDateRange($vevent, $item, $allDay);

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
        $detail = $item['detail'];
        $allDay = (bool) ($item['allDay'] ?? 1);
        $vcal = $this->createCalendar($item['timezone'] ?? null);
        $dtStamp = $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? 'now';

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => new DateTimeImmutable($dtStamp, new DateTimeZone('UTC')),
            'SUMMARY' => (string) $item['title'],
        ];

        if (!empty($detail['description'])) {
            $properties['DESCRIPTION'] = (string) $detail['description'];
        }

        $vevent = $vcal->add('VEVENT', $properties);
        $this->addDateRange($vevent, $item, $allDay);

        return $vcal->serialize();
    }

    /**
     * Setzt DTSTART/DTEND auf einem VEVENT. Ganztägige Einträge werden als
     * VALUE=DATE mit exklusivem DTEND (End-Tag + 1 Tag) serialisiert, getaktete
     * Einträge als UTC DATE-TIME aus Datum + Uhrzeit.
     *
     * @param \Sabre\VObject\Node $vevent
     * @param array<string, mixed> $item
     */
    private function addDateRange(\Sabre\VObject\Node $vevent, array $item, bool $allDay): void
    {
        if ($allDay) {
            $startDt = DateTimeValue::parseDate((string) $item['startDate']);
            $endDt = DateTimeValue::parseDate((string) $item['endDate'])->modify('+1 day');
            $vevent->add('DTSTART', $startDt, ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $endDt, ['VALUE' => 'DATE']);
            $vevent->add('TRANSP', 'TRANSPARENT');
            return;
        }

        $startDt = DateTimeValue::parseInstant((string) $item['startAt'])
            ->setTimezone(new DateTimeZone('UTC'));
        $endDt = DateTimeValue::parseInstant((string) $item['endAt'])
            ->setTimezone(new DateTimeZone('UTC'));
        $vevent->add('DTSTART', $startDt);
        $vevent->add('DTEND', $endDt);
    }

    /** @param array<string, mixed> $item */
    private function ptJourneyToIcs(array $item): string
    {
        $vcal = $this->createCalendar($item['timezone'] ?? 'UTC');
        $detail = $item['detail'];

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? 'now',
            'DTSTART' => DateTimeValue::parseInstant((string) $item['startAt'])->setTimezone(new DateTimeZone('UTC')),
            'DTEND' => DateTimeValue::parseInstant((string) $item['endAt'])->setTimezone(new DateTimeZone('UTC')),
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
        $dateStr = $detail['occurrenceDate'] ?? $item['startDate'];
        $startDt = new DateTimeImmutable($dateStr, new DateTimeZone('UTC'));
        $endDt = $startDt->modify('+1 day');

        $properties = [
            'UID' => $this->feedItemUid($item),
            'DTSTAMP' => $detail['updatedAt'] ?? $detail['createdAt'] ?? $item['startDate'] ?? $item['startAt'] ?? 'now',
            'SUMMARY' => (string) $item['title'],
            'TRANSP' => 'TRANSPARENT',
            'RRULE' => 'FREQ=YEARLY',
        ];

        $vevent = $vcal->add('VEVENT', $properties);
        $vevent->add('DTSTART', $startDt, ['VALUE' => 'DATE']);
        $vevent->add('DTEND', $endDt, ['VALUE' => 'DATE']);

        return $vcal->serialize();
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function createCalendar(?string $timezone = null): VCalendar
    {
        $calendar = new VCalendar([
            'VERSION' => '2.0',
            'PRODID' => self::PRODID,
            'CALSCALE' => 'GREGORIAN',
        ]);

        if ($timezone !== null && trim($timezone) !== '') {
            try {
                $calendar->add('X-WR-TIMEZONE', DateTimeValue::assertTimeZone($timezone)->getName());
            } catch (\InvalidArgumentException) {
            }
        }

        return $calendar;
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
