<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\PollOptionRepository;
use Sinclear\Api\Repository\PollVoteRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;
use Sinclear\Api\Support\PollAnonymity;

/**
 * Anonyme Abstimmung: einmalige Stimme pro Nutzer/Option, Ergebnisse erst
 * nach Schließen.
 */
final readonly class PollVoteService
{
    public function __construct(
        private PollService $pollService,
        private PollOptionRepository $optionRepo,
        private PollVoteRepository $voteRepo,
        private PollPolicy $policy,
        private PollAnonymity $anonymity,
    ) {}

    /**
     * @return array{hasVoted: bool, votedOptionIds: string[]}
     */
    public function voteStatus(AuthenticatedUser $user, string $pollId): array
    {
        $poll = $this->loadVote($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canView($user, $poll, $isInvited)) {
            throw new \RuntimeException('forbidden');
        }

        $hash = $this->anonymity->participantHash($pollId, $user->id);
        $votedOptionIds = [];
        foreach ($this->voteRepo->listByPoll($pollId) as $vote) {
            if (hash_equals($hash, (string) $vote['participantHash'])) {
                $votedOptionIds[] = (string) $vote['optionId'];
            }
        }

        return [
            'hasVoted' => $votedOptionIds !== [],
            'votedOptionIds' => $votedOptionIds,
        ];
    }

    /**
     * @param string[] $optionIds
     */
    public function vote(AuthenticatedUser $user, string $pollId, array $optionIds): void
    {
        $poll = $this->loadVote($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canVote($user, $poll, $isInvited)) {
            throw new \RuntimeException($poll['status'] === 'closed' ? 'poll_closed' : 'forbidden');
        }

        $validOptionIds = [];
        foreach ($this->optionRepo->listByPoll($pollId) as $option) {
            $validOptionIds[] = (string) $option['id'];
        }

        $selected = [];
        foreach ($optionIds as $optionId) {
            $optionId = trim((string) $optionId);
            if ($optionId === '' || !in_array($optionId, $validOptionIds, true)) {
                throw new \RuntimeException('invalid_option');
            }
            $selected[] = $optionId;
        }
        $selected = array_values(array_unique($selected));
        if ($selected === []) {
            throw new \RuntimeException('invalid_answer');
        }
        if ((int) ($poll['allowMultiple'] ?? 0) !== 1 && count($selected) > 1) {
            throw new \RuntimeException('invalid_answer');
        }

        $hash = $this->anonymity->participantHash($pollId, $user->id);
        if ($this->voteRepo->hasVoted($pollId, $hash)) {
            throw new \RuntimeException('already_voted');
        }

        $this->voteRepo->create($pollId, $selected, $hash);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function results(AuthenticatedUser $user, string $pollId): array
    {
        $poll = $this->loadVote($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canSeeResults($user, $poll, $isInvited)) {
            throw new \RuntimeException($poll['status'] === 'closed' ? 'forbidden' : 'results_hidden');
        }

        $counts = $this->voteRepo->countsByOption($pollId);
        $data = [];
        foreach ($this->optionRepo->listByPoll($pollId) as $option) {
            $optionId = (string) $option['id'];
            $data[] = [
                'optionId' => $optionId,
                'label' => $option['label'],
                'votes' => $counts[$optionId] ?? 0,
            ];
        }

        $totalVoters = $this->voteRepo->countParticipants($pollId);
        foreach ($data as &$row) {
            $row['percentage'] = $totalVoters > 0 ? round($row['votes'] / $totalVoters * 100, 1) : 0.0;
        }
        unset($row);

        return [
            'data' => $data,
            'meta' => ['totalParticipants' => $totalVoters],
        ];
    }

    private function loadVote(string $pollId): array
    {
        $poll = $this->pollService->loadPollOrFail($pollId);
        if (($poll['type'] ?? '') !== 'vote') {
            throw new \RuntimeException('invalid_type');
        }
        return $poll;
    }
}
