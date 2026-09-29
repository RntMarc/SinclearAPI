<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Support\TravelPlanningError;

class TravelPlanningErrorTest extends TestCase
{
    public function testForbiddenCodes(): void
    {
        $this->assertSame(['forbidden', 403], TravelPlanningError::resolve('Not a planning member'));
        $this->assertSame(['forbidden', 403], TravelPlanningError::resolve('Not a planning leader'));
        $this->assertSame(['forbidden', 403], TravelPlanningError::resolve('Not allowed'));
    }

    public function testNotFoundCodes(): void
    {
        $this->assertSame(['planning_trip_not_found', 404], TravelPlanningError::resolve('Planning trip not found'));
        $this->assertSame(['date_option_not_found', 404], TravelPlanningError::resolve('Date option not found'));
        $this->assertSame(['member_not_found', 404], TravelPlanningError::resolve('Member not found'));
    }

    public function testConflictCodes(): void
    {
        $this->assertSame(['already_member', 409], TravelPlanningError::resolve('Already a member'));
        $this->assertSame(['last_leader', 409], TravelPlanningError::resolve('Last leader remains'));
        $this->assertSame(['trip_already_active', 409], TravelPlanningError::resolve('Trip already active'));
    }

    public function testValidationCodes(): void
    {
        $this->assertSame(['name_required', 400], TravelPlanningError::resolve('Name required'));
        $this->assertSame(['invalid_topic', 400], TravelPlanningError::resolve('Invalid topic'));
        $this->assertSame(['invalid_availability', 400], TravelPlanningError::resolve('Invalid availability'));
        $this->assertSame(['invalid_interest', 400], TravelPlanningError::resolve('Invalid interest'));
        $this->assertSame(['invalid_direction', 400], TravelPlanningError::resolve('Invalid direction'));
        $this->assertSame(['invalid_price', 400], TravelPlanningError::resolve('Invalid price'));
        $this->assertSame(['invalid_timezone', 400], TravelPlanningError::resolve('invalid_timezone'));
    }

    public function testUnknownCodeFallsBackToInternalError(): void
    {
        $this->assertSame(['internal_error', 500], TravelPlanningError::resolve('Some unexpected failure'));
    }
}
