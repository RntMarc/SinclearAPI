<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sinclear\Api\Services\CalendarFeedService;

/**
 * Read-only CalDAV-Backend.
 *
 * Stellt drei Kalender je Principal bereit:
 *   1. "Beyond Kalender"  – CalendarEvents
 *   2. "Reisen & Fahrten" – Trips + TravelEvents + PtJourneys
 *   3. "Geburtstage"      – Geburtstage sichtbarer Nutzer
 *
 * Die Daten stammen aus dem CalendarFeedService (identisch zu GET /calendar/all).
 * Fuer den virtuellen Invalid-Token-Principal wird ein Hinweis-Kalender mit
 * genau einem Event ausgeliefert. Schreibzugriffe werden grundsaetzlich verweigert.
 */
final class CalDavBackend extends AbstractBackend
{
    public const string INVALID_TOKEN_CALENDAR_ID = 'calendar:dav-invalid-token';

    private const string CALENDAR_PREFIX = 'calendar:';
    private const string TRAVEL_PREFIX = 'travel:';
    private const string BIRTHDAY_PREFIX = 'birthdays:';

    private const string CALENDAR_URI = 'calendar';
    private const string TRAVEL_URI = 'travel';
    private const string BIRTHDAY_URI = 'birthdays';

    /** @var array<string, array{uri: string, types: list<string>, displayName: string, color: string, order: int}> */
    private const array CALENDAR_CONFIG = [
        self::CALENDAR_PREFIX => [
            'uri' => self::CALENDAR_URI,
            'types' => ['calendar_event'],
            'displayName' => 'Beyond Kalender',
            'color' => '#6366f1',
            'order' => '1',
        ],
        self::TRAVEL_PREFIX => [
            'uri' => self::TRAVEL_URI,
            'types' => ['travel_event', 'trip', 'pt_journey'],
            'displayName' => 'Reisen & Fahrten',
            'color' => '#f59e0b',
            'order' => '2',
        ],
        self::BIRTHDAY_PREFIX => [
            'uri' => self::BIRTHDAY_URI,
            'types' => ['birthday'],
            'displayName' => 'Geburtstage',
            'color' => '#ec4899',
            'order' => '3',
        ],
    ];

    public function __construct(
        private CalendarFeedService $feedService,
        private IcsFactory $icsFactory,
        private DavDummyFactory $dummyFactory,
    ) {}

    public function getCalendarsForUser($principalUri)
    {
        if ($principalUri === DavAuthBackend::INVALID_TOKEN_PRINCIPAL) {
            return [$this->calendarInfo(self::INVALID_TOKEN_CALENDAR_ID, $principalUri, 'Sinclear Beyond', '#6366f1', '1')];
        }

        $userId = DavPrincipalBackend::userIdFromPrincipal((string) $principalUri);
        if ($userId === null) {
            return [];
        }

        return [
            $this->calendarInfo(self::CALENDAR_PREFIX . $userId, (string) $principalUri, 'Beyond Kalender', '#6366f1', '1'),
            $this->calendarInfo(self::TRAVEL_PREFIX . $userId, (string) $principalUri, 'Reisen & Fahrten', '#f59e0b', '2'),
            $this->calendarInfo(self::BIRTHDAY_PREFIX . $userId, (string) $principalUri, 'Geburtstage', '#ec4899', '3'),
        ];
    }

    public function getCalendarObjects($calendarId)
    {
        $calendarId = (string) $calendarId;

        if ($calendarId === self::INVALID_TOKEN_CALENDAR_ID) {
            return [$this->dummyFactory->calendarObject()];
        }

        $resolved = $this->resolveCalendarId($calendarId);
        if ($resolved === null) {
            return [];
        }

        [$userId, $types] = $resolved;

        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 years')->format('Y-m-d H:i:s');

        $feed = $this->feedService->buildFeed($userId, $start, $end, $types);

        return array_map(
            fn(array $item) => $this->icsFactory->feedItemToCalendarObject($item),
            $feed['data'],
        );
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $calendarId = (string) $calendarId;

        if ($calendarId === self::INVALID_TOKEN_CALENDAR_ID) {
            $object = $this->dummyFactory->calendarObject();
            return $object['uri'] === $objectUri ? $object : null;
        }

        $resolved = $this->resolveCalendarId($calendarId);
        if ($resolved === null) {
            return null;
        }

        [$userId, $types] = $resolved;

        $objectId = str_ends_with($objectUri, '.ics') ? substr($objectUri, 0, -4) : $objectUri;

        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 years')->format('Y-m-d H:i:s');

        $feed = $this->feedService->buildFeed($userId, $start, $end, $types);

        foreach ($feed['data'] as $item) {
            if ($item['id'] === $objectId) {
                return $this->icsFactory->feedItemToCalendarObject($item);
            }
        }

        return null;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        throw new Forbidden('Calendars are read-only');
    }

    public function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch)
    {
        throw new Forbidden('Calendars are read-only');
    }

    public function deleteCalendar($calendarId)
    {
        throw new Forbidden('Calendars are read-only');
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        throw new Forbidden('This calendar is read-only');
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        throw new Forbidden('This calendar is read-only');
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        throw new Forbidden('This calendar is read-only');
    }

    // ─── Private helpers ───────────────────────────────────────────────

    /** @return array{string, list<string>}|null */
    private function resolveCalendarId(string $calendarId): ?array
    {
        foreach (self::CALENDAR_CONFIG as $prefix => $config) {
            if (str_starts_with($calendarId, $prefix)) {
                $userId = substr($calendarId, strlen($prefix));
                if ($userId !== '') {
                    return [$userId, $config['types']];
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function calendarInfo(string $id, string $principalUri, string $displayName, string $color, string $order): array
    {
        $uri = self::CALENDAR_URI;
        foreach (self::CALENDAR_CONFIG as $prefix => $config) {
            if (str_starts_with($id, $prefix)) {
                $uri = $config['uri'];
                break;
            }
        }

        return [
            'id' => $id,
            'uri' => $uri,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $displayName,
            '{http://apple.com/ns/ical/}calendar-color' => $color,
            '{http://apple.com/ns/ical/}calendar-order' => $order,
            '{http://sabredav.org/ns}read-only' => 1,
            '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ];
    }
}
