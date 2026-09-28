<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\TravelPolicy;

class TravelPolicyTest extends TestCase
{
    private TravelPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TravelPolicy();
    }

    private function user(string $id = 'user-1', bool $isAdmin = false): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id . '@test.com', $isAdmin, 'jti');
    }

    // ── canViewTrip ──────────────────────────────────────

    public function testParticipantCanViewTrip(): void
    {
        $this->assertTrue($this->policy->canViewTrip($this->user(), true));
    }

    public function testStrangerCannotViewTrip(): void
    {
        $this->assertFalse($this->policy->canViewTrip($this->user(), false));
    }

    public function testAdminCanViewTrip(): void
    {
        $this->assertTrue($this->policy->canViewTrip($this->user('admin', true), false));
    }

    // ── canManageTrip ────────────────────────────────────

    public function testLeaderCanManageTrip(): void
    {
        $this->assertTrue($this->policy->canManageTrip($this->user(), 'leader'));
    }

    public function testParticipantCannotManageTrip(): void
    {
        $this->assertFalse($this->policy->canManageTrip($this->user(), 'participant'));
    }

    public function testNonParticipantCannotManageTrip(): void
    {
        $this->assertFalse($this->policy->canManageTrip($this->user(), null));
    }

    public function testAdminCanManageTripEvenWithoutRole(): void
    {
        $this->assertTrue($this->policy->canManageTrip($this->user('admin', true), null));
    }

    // ── canManageEvent (Reise-Event erbt, Standalone eigene Rolle) ──

    public function testTripEventInheritsLeaderRights(): void
    {
        $this->assertTrue($this->policy->canManageEvent($this->user(), false, 'leader', null));
    }

    public function testTripEventParticipantCannotManage(): void
    {
        $this->assertFalse($this->policy->canManageEvent($this->user(), false, 'participant', 'leader'));
    }

    public function testStandaloneEventLeaderCanManage(): void
    {
        $this->assertTrue($this->policy->canManageEvent($this->user(), true, null, 'leader'));
    }

    public function testStandaloneEventParticipantCannotManage(): void
    {
        $this->assertFalse($this->policy->canManageEvent($this->user(), true, null, 'participant'));
    }

    public function testAdminCanManageStandaloneEvent(): void
    {
        $this->assertTrue($this->policy->canManageEvent($this->user('admin', true), true, null, null));
    }

    // ── Rollenvergabe ────────────────────────────────────

    public function testLeaderCanAssignTripRole(): void
    {
        $this->assertTrue($this->policy->canAssignTripRole($this->user(), 'leader'));
    }

    public function testParticipantCannotAssignTripRole(): void
    {
        $this->assertFalse($this->policy->canAssignTripRole($this->user(), 'participant'));
    }

    public function testEventLeaderCanAssignEventRole(): void
    {
        $this->assertTrue($this->policy->canAssignEventRole($this->user(), 'leader'));
    }

    public function testEventParticipantCannotAssignEventRole(): void
    {
        $this->assertFalse($this->policy->canAssignEventRole($this->user(), 'participant'));
    }

    // ── Konversion ───────────────────────────────────────

    public function testLeaderOfSourceAndDestinationCanConvert(): void
    {
        $this->assertTrue($this->policy->canConvert($this->user(), 'leader', 'leader'));
    }

    public function testLeaderOfSourceOnlyCannotConvert(): void
    {
        $this->assertFalse($this->policy->canConvert($this->user(), 'leader', 'participant'));
    }

    public function testNonLeaderCannotConvert(): void
    {
        $this->assertFalse($this->policy->canConvert($this->user(), 'participant', 'participant'));
    }

    public function testAdminCanConvert(): void
    {
        $this->assertTrue($this->policy->canConvert($this->user('admin', true), null, null));
    }

    // ── letzter Leader ───────────────────────────────────

    public function testCannotRemoveLastLeader(): void
    {
        $this->assertFalse($this->policy->canRemoveLeader(true));
    }

    public function testCanRemoveLeaderWhenOthersRemain(): void
    {
        $this->assertTrue($this->policy->canRemoveLeader(false));
    }
}
