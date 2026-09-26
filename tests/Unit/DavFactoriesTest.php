<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use Sinclear\Api\Dav\DavDummyFactory;
use Sinclear\Api\Dav\IcsFactory;
use Sinclear\Api\Dav\VcardFactory;

class DavFactoriesTest extends TestCase
{
    private IcsFactory $icsFactory;
    private VcardFactory $vcardFactory;
    private DavDummyFactory $dummyFactory;

    protected function setUp(): void
    {
        $this->icsFactory = new IcsFactory();
        $this->vcardFactory = new VcardFactory();
        $this->dummyFactory = new DavDummyFactory($this->icsFactory, $this->vcardFactory);
    }

    public function testEventToIcsSerializesUtcTimesAndClass(): void
    {
        $event = [
            'id' => 'event-1',
            'creatorId' => 'user-1',
            'title' => 'Team, Meeting',
            'description' => "Zeile 1\nZeile 2; mit Sonderzeichen",
            'allDay' => false,
            'timezone' => 'UTC',
            'startAt' => '2026-07-01T10:00:00Z',
            'endAt' => '2026-07-01T11:00:00Z',
            'visibility' => 0,
            'createdAt' => '2026-06-26 10:00:00',
            'updatedAt' => '2026-06-27 10:00:00',
        ];

        $object = $this->icsFactory->eventToCalendarObject($event, [
            ['id' => 'user-2', 'displayName' => 'Max'],
        ]);

        self::assertSame('event-1.ics', $object['uri']);
        self::assertSame('vevent', $object['component']);
        self::assertSame('"' . sha1($object['calendardata']) . '"', $object['etag']);
        self::assertSame(strlen($object['calendardata']), $object['size']);

        $vcal = Reader::read($object['calendardata']);
        self::assertSame('event-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('20260701T100000Z', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260701T110000Z', (string) $vcal->VEVENT->DTEND);
        self::assertSame('20260627T100000Z', (string) $vcal->VEVENT->DTSTAMP);
        self::assertSame('Team, Meeting', (string) $vcal->VEVENT->SUMMARY);
        self::assertStringContainsString('Zeile 1', (string) $vcal->VEVENT->DESCRIPTION);
        self::assertSame('PRIVATE', (string) $vcal->VEVENT->CLASS);
        self::assertSame('mailto:user-1@sinclear.de', (string) $vcal->VEVENT->ORGANIZER);
        self::assertSame('mailto:user-2@sinclear.de', (string) $vcal->VEVENT->ATTENDEE);
        self::assertSame('Max', (string) $vcal->VEVENT->ATTENDEE['CN']);
    }

    public function testVisibilityMapsToClass(): void
    {
        $base = [
            'id' => 'event-1',
            'creatorId' => 'user-1',
            'title' => 'T',
            'allDay' => false,
            'timezone' => 'UTC',
            'startAt' => '2026-07-01T10:00:00Z',
            'endAt' => '2026-07-01T11:00:00Z',
            'createdAt' => '2026-06-26 10:00:00',
            'updatedAt' => '2026-06-27 10:00:00',
        ];

        foreach ([0 => 'PRIVATE', 1 => 'PUBLIC', 2 => 'CONFIDENTIAL'] as $visibility => $class) {
            $object = $this->icsFactory->eventToCalendarObject($base + ['visibility' => $visibility]);
            $vcal = Reader::read($object['calendardata']);
            self::assertSame($class, (string) $vcal->VEVENT->CLASS);
        }
    }

    public function testUserToVcardRespectsFilteredFields(): void
    {
        $object = $this->vcardFactory->userToCardObject([
            'id' => 'user-1',
            'displayName' => 'Max, Mustermann',
            'email' => 'max@example.com',
            'birthday' => '1990-05-04',
            'createdAt' => '2026-01-01 10:00:00',
        ]);

        self::assertSame('user-1.vcf', $object['uri']);
        self::assertSame('"' . sha1($object['carddata']) . '"', $object['etag']);

        $vcard = Reader::read($object['carddata']);
        self::assertSame('3.0', (string) $vcard->VERSION);
        self::assertSame('user-1@sinclear.de', (string) $vcard->UID);
        self::assertSame('Max, Mustermann', (string) $vcard->FN);
        self::assertSame('max@example.com', (string) $vcard->EMAIL);
        self::assertSame('1990-05-04', (string) $vcard->BDAY);
    }

    public function testUserToVcardWithoutVisibleFields(): void
    {
        $object = $this->vcardFactory->userToCardObject([
            'id' => 'user-2',
            'displayName' => 'Bob',
            'createdAt' => '2026-01-01 10:00:00',
        ]);

        $vcard = Reader::read($object['carddata']);
        self::assertSame('Bob', (string) $vcard->FN);
        self::assertNull($vcard->EMAIL);
        self::assertNull($vcard->BDAY);
    }

    public function testDummyCalendarObjectIsTodayNoonUtc(): void
    {
        $object = $this->dummyFactory->calendarObject();
        self::assertSame('sinclear-dav-invalid-token.ics', $object['uri']);

        $vcal = Reader::read($object['calendardata']);
        self::assertSame('Abgelaufener oder ungültiger Token', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('sinclear-dav-invalid-token@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame(gmdate('Ymd\T120000\Z'), (string) $vcal->VEVENT->DTSTART);
        self::assertSame(gmdate('Ymd\T130000\Z'), (string) $vcal->VEVENT->DTEND);
    }

    public function testDummyCardObject(): void
    {
        $object = $this->dummyFactory->cardObject();
        self::assertSame('sinclear-dav-invalid-token.vcf', $object['uri']);

        $vcard = Reader::read($object['carddata']);
        self::assertSame('Abgelaufener oder ungültiger Token', (string) $vcard->FN);
        self::assertStringContainsString('Token ist entweder abgelaufen oder ungültig', (string) $vcard->NOTE);
    }

    public function testFeedItemCalendarEvent(): void
    {
        $item = [
            'type' => 'calendar_event',
            'id' => 'evt-1',
            'title' => 'Meeting',
            'allDay' => false,
            'timezone' => 'UTC',
            'startAt' => '2026-07-01T10:00:00Z',
            'endAt' => '2026-07-01T11:00:00Z',
            'detail' => [
                'id' => 'evt-1',
                'creatorId' => 'user-1',
                'title' => 'Meeting',
                'description' => 'Beschreibung',
                'allDay' => false,
                'timezone' => 'UTC',
                'startAt' => '2026-07-01T10:00:00Z',
                'endAt' => '2026-07-01T11:00:00Z',
                'visibility' => 1,
                'createdAt' => '2026-06-26 10:00:00',
                'updatedAt' => '2026-06-27 10:00:00',
            ],
        ];

        $object = $this->icsFactory->feedItemToCalendarObject($item);
        self::assertSame('evt-1.ics', $object['uri']);
        self::assertSame('vevent', $object['component']);

        $vcal = Reader::read($object['calendardata']);
        self::assertSame('evt-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('20260701T100000Z', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260701T110000Z', (string) $vcal->VEVENT->DTEND);
        self::assertSame('Meeting', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('PUBLIC', (string) $vcal->VEVENT->CLASS);
        self::assertStringContainsString('Beschreibung', (string) $vcal->VEVENT->DESCRIPTION);
    }

    public function testFeedItemTravelEvent(): void
    {
        $item = [
            'type' => 'travel_event',
            'id' => 'te-1',
            'title' => 'Konzert',
            'allDay' => false,
            'timezone' => 'UTC',
            'startAt' => '2026-08-15T18:00:00Z',
            'endAt' => '2026-08-15T22:00:00Z',
            'detail' => [
                'id' => 'te-1',
                'name' => 'Konzert',
                'description' => 'Rockkonzert',
                'allDay' => false,
                'timezone' => 'UTC',
                'startAt' => '2026-08-15T18:00:00Z',
                'endAt' => '2026-08-15T22:00:00Z',
                'participants' => [
                    ['id' => 'user-1', 'displayName' => 'Max'],
                ],
            ],
        ];

        $object = $this->icsFactory->feedItemToCalendarObject($item);
        $vcal = Reader::read($object['calendardata']);
        self::assertSame('te-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('Konzert', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('20260815T180000Z', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260815T220000Z', (string) $vcal->VEVENT->DTEND);
        self::assertSame('mailto:user-1@sinclear.de', (string) $vcal->VEVENT->ATTENDEE);
    }

    public function testFeedItemTripIsAllDay(): void
    {
        $item = [
            'type' => 'trip',
            'id' => 'trip-1',
            'title' => 'Italien-Reise',
            'allDay' => true,
            'startDate' => '2026-09-01',
            'endDate' => '2026-09-14',
            'detail' => [
                'id' => 'trip-1',
                'name' => 'Italien-Reise',
                'description' => 'Sommerurlaub',
                'allDay' => true,
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-14',
            ],
        ];

        $object = $this->icsFactory->feedItemToCalendarObject($item);
        $vcal = Reader::read($object['calendardata']);
        self::assertSame('trip-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('Italien-Reise', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('TRANSPARENT', (string) $vcal->VEVENT->TRANSP);
        self::assertNull($vcal->VEVENT->CLASS);
        self::assertSame('20260901', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260915', (string) $vcal->VEVENT->DTEND);
    }

    public function testFeedItemPtJourneyWithLegs(): void
    {
        $item = [
            'type' => 'pt_journey',
            'id' => 'pt-1',
            'title' => 'Berlin → München',
            'allDay' => false,
            'timezone' => 'UTC',
            'startAt' => '2026-07-10T08:00:00Z',
            'endAt' => '2026-07-10T12:30:00Z',
            'detail' => [
                'id' => 'pt-1',
                'fromStationName' => 'Berlin Hbf',
                'toStationName' => 'München Hbf',
                'departureTime' => '2026-07-10 08:00:00',
                'arrivalTime' => '2026-07-10 12:30:00',
                'duration' => 270,
                'transfers' => 1,
                'legs' => [
                    [
                        'lineName' => 'ICE 123',
                        'fromStationName' => 'Berlin Hbf',
                        'toStationName' => 'Nürnberg Hbf',
                        'plannedDeparture' => '2026-07-10 08:00:00',
                        'plannedArrival' => '2026-07-10 11:00:00',
                    ],
                    [
                        'lineName' => 'ICE 456',
                        'fromStationName' => 'Nürnberg Hbf',
                        'toStationName' => 'München Hbf',
                        'plannedDeparture' => '2026-07-10 11:15:00',
                        'plannedArrival' => '2026-07-10 12:30:00',
                    ],
                ],
            ],
        ];

        $object = $this->icsFactory->feedItemToCalendarObject($item);
        $vcal = Reader::read($object['calendardata']);
        self::assertSame('pt-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('Berlin → München', (string) $vcal->VEVENT->SUMMARY);
        self::assertStringContainsString('Berlin Hbf', (string) $vcal->VEVENT->DESCRIPTION);
        self::assertStringContainsString('ICE 123', (string) $vcal->VEVENT->DESCRIPTION);
    }

    public function testFeedItemBirthdayIsAllDayYearly(): void
    {
        $item = [
            'type' => 'birthday',
            'id' => 'bd-2026-05-04-user-1',
            'title' => 'Geburtstag: Max',
            'allDay' => true,
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-04',
            'detail' => [
                'userId' => 'user-1',
                'displayName' => 'Max',
                'birthday' => '1990-05-04',
                'occurrenceDate' => '2026-05-04',
            ],
        ];

        $object = $this->icsFactory->feedItemToCalendarObject($item);
        $vcal = Reader::read($object['calendardata']);
        self::assertSame('bd-2026-05-04-user-1@sinclear.de', (string) $vcal->VEVENT->UID);
        self::assertSame('Geburtstag: Max', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('TRANSPARENT', (string) $vcal->VEVENT->TRANSP);
        self::assertStringContainsString('FREQ=YEARLY', (string) $vcal->VEVENT->RRULE);
        self::assertSame('20260504', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260505', (string) $vcal->VEVENT->DTEND);
    }
}
