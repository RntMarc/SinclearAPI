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
            'startTime' => '2026-07-01 10:00:00',
            'endTime' => '2026-07-01 11:00:00',
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
            'startTime' => '2026-07-01 10:00:00',
            'endTime' => '2026-07-01 11:00:00',
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
}
