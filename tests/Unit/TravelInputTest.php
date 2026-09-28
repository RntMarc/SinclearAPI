<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Support\TravelInput;

class TravelInputTest extends TestCase
{
    public function testAllDayTimingFromBody(): void
    {
        $result = TravelInput::timingFromBody([
            'allDay' => true,
            'timezone' => 'Europe/Berlin',
            'startDate' => '2026-09-26',
            'endDate' => '2026-09-28',
        ], true);

        $this->assertSame(1, $result['allDay']);
        $this->assertSame('2026-09-26', $result['startDate']);
        $this->assertSame('2026-09-28', $result['endDate']);
        $this->assertNull($result['startAt']);
        $this->assertNull($result['endAt']);
        $this->assertSame('Europe/Berlin', $result['timezone']);
    }

    public function testTimedTimingFromBodyConvertsToUtcDatabaseValue(): void
    {
        $result = TravelInput::timingFromBody([
            'allDay' => false,
            'timezone' => 'Europe/Berlin',
            'startAt' => '2026-09-26T14:30:00+02:00',
            'endAt' => '2026-09-26T16:00:00+02:00',
        ]);

        $this->assertSame(0, $result['allDay']);
        $this->assertSame('2026-09-26 12:30:00', $result['startAt']);
        $this->assertSame('2026-09-26 14:00:00', $result['endAt']);
        $this->assertNull($result['startDate']);
        $this->assertNull($result['endDate']);
    }

    public function testAllDayRequiresDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('date_required');
        TravelInput::timingFromBody(['allDay' => true], true);
    }

    public function testTimedRequiresInstants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('time_required');
        TravelInput::timingFromBody(['allDay' => false]);
    }

    public function testInvalidRangeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_time_range');
        TravelInput::timingFromBody([
            'allDay' => false,
            'startAt' => '2026-09-26T16:00:00+02:00',
            'endAt' => '2026-09-26T14:00:00+02:00',
        ]);
    }

    public function testInvalidTimezoneRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_timezone');
        TravelInput::timingFromBody([
            'allDay' => true,
            'timezone' => 'Not/AZone',
            'startDate' => '2026-09-26',
            'endDate' => '2026-09-28',
        ], true);
    }

    public function testHasTimingFields(): void
    {
        $this->assertTrue(TravelInput::hasTimingFields(['startDate' => '2026-01-01']));
        $this->assertFalse(TravelInput::hasTimingFields(['name' => 'x']));
    }

    public function testNullableStringTrimsAndNormalizesEmpty(): void
    {
        $this->assertSame('abc', TravelInput::nullableString(['x' => '  abc '], 'x'));
        $this->assertNull(TravelInput::nullableString(['x' => '   '], 'x'));
        $this->assertNull(TravelInput::nullableString([], 'x'));
    }

    public function testDetectTripChanges(): void
    {
        $old = ['name' => 'Alt', 'description' => 'Beschreibung'];
        $changed = TravelInput::detectTripChanges($old, ['name' => 'Neu']);

        $this->assertContains('Name', $changed);
        $this->assertNotContains('Beschreibung', $changed);
    }

    public function testDetectEventChangesIgnoresUnknownFields(): void
    {
        $old = ['name' => 'Alt', 'citySlug' => null];
        $changed = TravelInput::detectEventChanges($old, ['name' => 'Neu', 'trip' => 'trip-1']);

        $this->assertContains('Name', $changed);
        $this->assertNotContains('trip', $changed);
    }
}
