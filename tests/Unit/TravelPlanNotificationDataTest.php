<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\NotificationService;

/**
 * DB-freier Test der Planungs-Relations-Normalisierung im NotificationService.
 * Die private Methode wird per Reflection ohne Konstruktor (und damit ohne
 * PDO/Repositories) aufgerufen.
 */
class TravelPlanNotificationDataTest extends TestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    private function normalize(string $type, ?array $data): array
    {
        $method = new \ReflectionMethod(NotificationService::class, 'normalizeData');
        return $method->invoke($this->service, $type, $data);
    }

    public function testInviteNormalization(): void
    {
        $result = $this->normalize('trip_planning_invite', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'inviter', 'object' => 'User', 'identifier' => 'u1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('trip', $result[0]['relation']);
        $this->assertSame('inviter', $result[1]['relation']);
    }

    public function testInviteMissingRelationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalize('trip_planning_invite', [
            ['relation' => 'inviter', 'object' => 'User', 'identifier' => 'u1'],
        ]);
    }

    public function testResponseNormalization(): void
    {
        $result = $this->normalize('trip_planning_response', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'responder', 'object' => 'User', 'identifier' => 'u1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('responder', $result[1]['relation']);
    }

    public function testFinalizedWithoutOptionalRelation(): void
    {
        $result = $this->normalize('trip_planning_finalized', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('trip', $result[0]['relation']);
    }

    public function testFinalizedWithDateOption(): void
    {
        $result = $this->normalize('trip_planning_finalized', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'date_option', 'object' => 'TravelPlanDateOption', 'identifier' => 'd1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('TravelPlanDateOption', $result[1]['object']);
    }

    public function testFinalizedWithAccommodationOption(): void
    {
        $result = $this->normalize('trip_planning_finalized', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'accommodation_option', 'object' => 'TravelPlanAccommodationOption', 'identifier' => 'a1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('accommodation_option', $result[1]['relation']);
    }

    public function testFinalizedWithEvent(): void
    {
        $result = $this->normalize('trip_planning_finalized', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'event', 'object' => 'TravelPlanEvent', 'identifier' => 'e1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('TravelPlanEvent', $result[1]['object']);
    }

    public function testFinalizedUnsupportedPairThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalize('trip_planning_finalized', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
            ['relation' => 'event', 'object' => 'Trip', 'identifier' => 'e1'],
        ]);
    }

    public function testActivatedNormalization(): void
    {
        $result = $this->normalize('trip_planning_activated', [
            ['relation' => 'trip', 'object' => 'Trip', 'identifier' => 't1'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('trip', $result[0]['relation']);
    }
}
