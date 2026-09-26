<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;

class PollPolicyTest extends TestCase
{
    private PollPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PollPolicy();
    }

    private function user(string $id = 'user-1', bool $isAdmin = false): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id . '@test.com', $isAdmin, 'jti');
    }

    private function poll(array $overrides = []): array
    {
        return array_merge([
            'id' => 'poll-1',
            'type' => 'appointment',
            'creatorId' => 'creator-1',
            'status' => 'open',
            'accessMode' => 'invited',
            'submissionMode' => 'single',
            'resultsVisibility' => 'creator',
            'allowCounterProposals' => 0,
        ], $overrides);
    }

    // ── canView ──────────────────────────────────────────

    public function testCreatorCanView(): void
    {
        $this->assertTrue($this->policy->canView($this->user('creator-1'), $this->poll(), false));
    }

    public function testAdminCanView(): void
    {
        $this->assertTrue($this->policy->canView($this->user('admin', true), $this->poll(), false));
    }

    public function testAllUsersPollIsVisible(): void
    {
        $this->assertTrue($this->policy->canView($this->user('stranger'), $this->poll(['accessMode' => 'all_users']), false));
    }

    public function testInvitedUserCanView(): void
    {
        $this->assertTrue($this->policy->canView($this->user('stranger'), $this->poll(), true));
    }

    public function testStrangerCannotViewInvitedPoll(): void
    {
        $this->assertFalse($this->policy->canView($this->user('stranger'), $this->poll(), false));
    }

    // ── canManage ────────────────────────────────────────

    public function testCreatorCanManage(): void
    {
        $this->assertTrue($this->policy->canManage($this->user('creator-1'), $this->poll()));
    }

    public function testStrangerCannotManage(): void
    {
        $this->assertFalse($this->policy->canManage($this->user('stranger'), $this->poll()));
    }

    // ── canRespond / canVote ─────────────────────────────

    public function testInvitedCanRespondOnOpenPoll(): void
    {
        $this->assertTrue($this->policy->canRespond($this->user('stranger'), $this->poll(), true));
    }

    public function testCannotRespondOnClosedPoll(): void
    {
        $this->assertFalse($this->policy->canRespond($this->user('stranger'), $this->poll(['status' => 'closed']), true));
    }

    public function testCanVoteOnOpenPoll(): void
    {
        $this->assertTrue($this->policy->canVote($this->user('stranger'), $this->poll(['type' => 'vote']), true));
    }

    // ── canEditResponse ──────────────────────────────────

    public function testOwnerCanEditSingleOpenResponse(): void
    {
        $poll = $this->poll(['type' => 'form', 'submissionMode' => 'single', 'status' => 'open']);
        $this->assertTrue($this->policy->canEditResponse($this->user('owner'), $poll, 'owner'));
    }

    public function testCannotEditMultipleModeResponse(): void
    {
        $poll = $this->poll(['type' => 'form', 'submissionMode' => 'multiple']);
        $this->assertFalse($this->policy->canEditResponse($this->user('owner'), $poll, 'owner'));
    }

    public function testCannotEditClosedResponse(): void
    {
        $poll = $this->poll(['type' => 'form', 'status' => 'closed']);
        $this->assertFalse($this->policy->canEditResponse($this->user('owner'), $poll, 'owner'));
    }

    public function testCannotEditOthersResponse(): void
    {
        $poll = $this->poll(['type' => 'form']);
        $this->assertFalse($this->policy->canEditResponse($this->user('other'), $poll, 'owner'));
    }

    // ── canSeeResults ────────────────────────────────────

    public function testCreatorSeesFormResults(): void
    {
        $this->assertTrue($this->policy->canSeeResults($this->user('creator-1'), $this->poll(['type' => 'form']), false));
    }

    public function testParticipantSeesResultsWhenConfigured(): void
    {
        $poll = $this->poll(['type' => 'form', 'resultsVisibility' => 'participants']);
        $this->assertTrue($this->policy->canSeeResults($this->user('stranger'), $poll, true));
    }

    public function testParticipantDoesNotSeeResultsWhenCreatorOnly(): void
    {
        $this->assertFalse($this->policy->canSeeResults($this->user('stranger'), $this->poll(['type' => 'form']), true));
    }

    public function testVoteResultsHiddenWhileOpen(): void
    {
        $this->assertFalse($this->policy->canSeeResults($this->user('creator-1'), $this->poll(['type' => 'vote']), false));
    }

    public function testVoteResultsVisibleToCreatorWhenClosed(): void
    {
        $poll = $this->poll(['type' => 'vote', 'status' => 'closed']);
        $this->assertTrue($this->policy->canSeeResults($this->user('creator-1'), $poll, false));
    }

    // ── canAddCounterProposal / canFinalize ──────────────

    public function testCanAddCounterProposalWhenEnabled(): void
    {
        $poll = $this->poll(['allowCounterProposals' => 1]);
        $this->assertTrue($this->policy->canAddCounterProposal($this->user('stranger'), $poll, true));
    }

    public function testCannotAddCounterProposalWhenDisabled(): void
    {
        $this->assertFalse($this->policy->canAddCounterProposal($this->user('stranger'), $this->poll(), true));
    }

    public function testCreatorCanFinalize(): void
    {
        $this->assertTrue($this->policy->canFinalize($this->user('creator-1'), $this->poll()));
    }

    public function testCannotFinalizeClosedPoll(): void
    {
        $this->assertFalse($this->policy->canFinalize($this->user('creator-1'), $this->poll(['status' => 'closed'])));
    }
}
