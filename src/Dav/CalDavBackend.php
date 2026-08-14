<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sinclear\Api\Repository\CalendarEventRepository;

/**
 * Read-only CalDAV-Backend.
 *
 * Stellt einen einzelnen Kalender "Beyond Kalender" je Principal bereit.
 * Fuer den virtuellen Invalid-Token-Principal wird ein Hinweis-Kalender mit
 * genau einem Event ("Abgelaufener oder ungueltiger Token", heute 12:00 UTC)
 * ausgeliefert. Schreibzugriffe werden grundsaetzlich verweigert.
 */
final class CalDavBackend extends AbstractBackend
{
    public const string INVALID_TOKEN_CALENDAR_ID = 'calendar:dav-invalid-token';
    public const string CALENDAR_URI = 'calendar';

    public function __construct(
        private CalendarEventRepository $calendarRepo,
        private IcsFactory $icsFactory,
        private DavDummyFactory $dummyFactory,
    ) {}

    public function getCalendarsForUser($principalUri)
    {
        if ($principalUri === DavAuthBackend::INVALID_TOKEN_PRINCIPAL) {
            return [$this->calendarInfo(self::INVALID_TOKEN_CALENDAR_ID, $principalUri, 'Sinclear Beyond')];
        }

        $userId = DavPrincipalBackend::userIdFromPrincipal((string) $principalUri);
        if ($userId === null) {
            return [];
        }

        return [$this->calendarInfo($this->calendarId($userId), (string) $principalUri, 'Beyond Kalender')];
    }

    public function getCalendarObjects($calendarId)
    {
        $calendarId = (string) $calendarId;

        if ($calendarId === self::INVALID_TOKEN_CALENDAR_ID) {
            return [$this->dummyFactory->calendarObject()];
        }

        $userId = $this->userIdFromCalendarId($calendarId);
        if ($userId === null) {
            return [];
        }

        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 years')->format('Y-m-d H:i:s');

        $events = $this->calendarRepo->findAllVisibleForDav($userId, $start, $end);
        $participantsByEvent = $this->calendarRepo->findParticipantsForEvents(
            array_column($events, 'id'),
        );

        return array_map(
            fn(array $event) => $this->icsFactory->eventToCalendarObject(
                $event,
                $participantsByEvent[$event['id']] ?? [],
            ),
            $events,
        );
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $calendarId = (string) $calendarId;

        if ($calendarId === self::INVALID_TOKEN_CALENDAR_ID) {
            $object = $this->dummyFactory->calendarObject();
            return $object['uri'] === $objectUri ? $object : null;
        }

        $userId = $this->userIdFromCalendarId($calendarId);
        if ($userId === null) {
            return null;
        }

        $eventId = str_ends_with($objectUri, '.ics') ? substr($objectUri, 0, -4) : $objectUri;
        $event = $this->calendarRepo->findVisibleByIdForDav($userId, $eventId);
        if ($event === null) {
            return null;
        }

        $participants = $this->calendarRepo->findParticipantsByEvent($event['id']);
        return $this->icsFactory->eventToCalendarObject($event, $participants);
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

    /** @return array<string, mixed> */
    private function calendarInfo(string $id, string $principalUri, string $displayName): array
    {
        return [
            'id' => $id,
            'uri' => self::CALENDAR_URI,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $displayName,
            '{http://apple.com/ns/ical/}calendar-color' => '#6366f1',
            '{http://apple.com/ns/ical/}calendar-order' => '1',
            '{http://sabredav.org/ns}read-only' => 1,
            '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ];
    }

    private function calendarId(string $userId): string
    {
        return 'calendar:' . $userId;
    }

    private function userIdFromCalendarId(string $calendarId): ?string
    {
        $prefix = 'calendar:';
        if (!str_starts_with($calendarId, $prefix)) {
            return null;
        }
        $userId = substr($calendarId, strlen($prefix));
        return $userId !== '' ? $userId : null;
    }
}
