<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Support\TravelError;

class TravelErrorTest extends TestCase
{
    public function testTripNotActiveIsConflict(): void
    {
        $this->assertSame(['trip_not_active', 409], TravelError::resolve('Trip not active'));
    }

    public function testUnknownCodeFallsBackToInternalError(): void
    {
        $this->assertSame(['internal_error', 500], TravelError::resolve('Some unexpected failure'));
    }
}
