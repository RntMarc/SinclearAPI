<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\TravelPlanningPolicy;

class TravelPlanningPolicyTest extends TestCase
{
    private TravelPlanningPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TravelPlanningPolicy();
    }

    private function user(string $id = 'user-1', bool $isAdmin = false): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id . '@test.com', $isAdmin, 'jti');
    }

    // ── canViewPlan ──────────────────────────────────────

    public function testActiveMemberCanView(): void
    {
        $this->assertTrue($this->policy->canViewPlan($this->user(), 'invited'));
        $this->assertTrue($this->policy->canViewPlan($this->user(), 'accepted'));
        $this->assertTrue($this->policy->canViewPlan($this->user(), 'declined'));
    }

    public function testInactiveMemberCannotView(): void
    {
        $this->assertFalse($this->policy->canViewPlan($this->user(), 'inactive'));
    }

    public function testNonMemberCannotView(): void
    {
        $this->assertFalse($this->policy->canViewPlan($this->user(), null));
    }

    public function testAdminCanView(): void
    {
        $this->assertTrue($this->policy->canViewPlan($this->user('admin', true), null));
    }

    // ── canManagePlan ────────────────────────────────────

    public function testLeaderCanManagePlan(): void
    {
        $this->assertTrue($this->policy->canManagePlan($this->user(), TravelPlanningPolicy::ROLE_LEADER));
    }

    public function testMemberCannotManagePlan(): void
    {
        $this->assertFalse($this->policy->canManagePlan($this->user(), TravelPlanningPolicy::ROLE_MEMBER));
        $this->assertFalse($this->policy->canManagePlan($this->user(), null));
    }

    public function testAdminCanManagePlan(): void
    {
        $this->assertTrue($this->policy->canManagePlan($this->user('admin', true), null));
    }

    // ── canManageOwn ─────────────────────────────────────

    public function testActiveMemberCanManageOwn(): void
    {
        $this->assertTrue($this->policy->canManageOwn($this->user(), 'accepted'));
        $this->assertTrue($this->policy->canManageOwn($this->user(), 'declined'));
    }

    public function testInactiveMemberCannotManageOwn(): void
    {
        $this->assertFalse($this->policy->canManageOwn($this->user(), 'inactive'));
        $this->assertFalse($this->policy->canManageOwn($this->user(), null));
    }

    // ── canManageSuggestion ──────────────────────────────

    public function testLeaderCanManageAnySuggestion(): void
    {
        $this->assertTrue($this->policy->canManageSuggestion(
            $this->user('leader-1'),
            TravelPlanningPolicy::ROLE_LEADER,
            'someone-else',
        ));
    }

    public function testProposerCanManageOwnSuggestion(): void
    {
        $this->assertTrue($this->policy->canManageSuggestion(
            $this->user('proposer-1'),
            TravelPlanningPolicy::ROLE_MEMBER,
            'proposer-1',
        ));
    }

    public function testMemberCannotManageForeignSuggestion(): void
    {
        $this->assertFalse($this->policy->canManageSuggestion(
            $this->user('member-1'),
            TravelPlanningPolicy::ROLE_MEMBER,
            'proposer-1',
        ));
    }

    public function testMemberCannotManageSuggestionWithoutProposer(): void
    {
        $this->assertFalse($this->policy->canManageSuggestion(
            $this->user('member-1'),
            TravelPlanningPolicy::ROLE_MEMBER,
            null,
        ));
    }

    public function testAdminCanManageSuggestion(): void
    {
        $this->assertTrue($this->policy->canManageSuggestion(
            $this->user('admin', true),
            null,
            null,
        ));
    }

    // ── canRemoveLeader ──────────────────────────────────

    public function testLastLeaderCannotBeRemoved(): void
    {
        $this->assertFalse($this->policy->canRemoveLeader(true));
    }

    public function testNonLastLeaderCanBeRemoved(): void
    {
        $this->assertTrue($this->policy->canRemoveLeader(false));
    }
}
