<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class PollPolicy
{
    /** @param array<string, mixed> $poll */
    public function canView(AuthenticatedUser $user, array $poll, bool $isInvited): bool
    {
        if ($user->isAdmin || $poll['creatorId'] === $user->id) {
            return true;
        }

        if (($poll['accessMode'] ?? 'invited') === 'all_users') {
            return true;
        }

        return $isInvited;
    }

    /** @param array<string, mixed> $poll */
    public function canManage(AuthenticatedUser $user, array $poll): bool
    {
        return $user->isAdmin || $poll['creatorId'] === $user->id;
    }

    /** @param array<string, mixed> $poll */
    public function canRespond(AuthenticatedUser $user, array $poll, bool $isInvited): bool
    {
        return $poll['status'] === 'open' && $this->canView($user, $poll, $isInvited);
    }

    /** @param array<string, mixed> $poll */
    public function canEditResponse(AuthenticatedUser $user, array $poll, string $responseUserId): bool
    {
        if ($poll['status'] !== 'open' || ($poll['submissionMode'] ?? 'single') !== 'single') {
            return false;
        }

        return $user->isAdmin || $user->id === $responseUserId;
    }

    /** @param array<string, mixed> $poll */
    public function canSeeResults(AuthenticatedUser $user, array $poll, bool $isInvited): bool
    {
        if (($poll['type'] ?? '') === 'vote') {
            return $poll['status'] === 'closed' && $this->canManage($user, $poll);
        }

        if ($this->canManage($user, $poll)) {
            return true;
        }

        return ($poll['resultsVisibility'] ?? 'creator') === 'participants'
            && $this->canView($user, $poll, $isInvited);
    }

    /** @param array<string, mixed> $poll */
    public function canAddCounterProposal(AuthenticatedUser $user, array $poll, bool $isInvited): bool
    {
        if ((int) ($poll['allowCounterProposals'] ?? 0) !== 1) {
            return false;
        }

        return $poll['status'] === 'open' && $this->canView($user, $poll, $isInvited);
    }

    /** @param array<string, mixed> $poll */
    public function canFinalize(AuthenticatedUser $user, array $poll): bool
    {
        return $poll['status'] === 'open' && $this->canManage($user, $poll);
    }

    /** @param array<string, mixed> $poll */
    public function canVote(AuthenticatedUser $user, array $poll, bool $isInvited): bool
    {
        return $poll['status'] === 'open' && $this->canView($user, $poll, $isInvited);
    }
}
