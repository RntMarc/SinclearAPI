<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\FeedbackVoteRepository;

final readonly class FeedbackService
{
    private const VALID_STATUSES = [
        'submitted', 'planned', 'next', 'in_progress',
        'done', 'cancelled', 'rejected', 'later',
    ];

    public function __construct(
        private FeedbackSuggestionRepository $suggestionRepo,
        private FeedbackVoteRepository $voteRepo,
    ) {}

    public function listSuggestions(int $page, int $limit, ?string $userId): array
    {
        $result = $this->suggestionRepo->list($page, $limit, $userId);
        $result['data'] = array_map(
            fn(array $s) => $this->formatSuggestion($s),
            $result['data']
        );
        return $result;
    }

    public function createSuggestion(string $userId, string $title, ?string $description): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('title_required');
        }

        $id = $this->suggestionRepo->create([
            'userId' => $userId,
            'title' => $title,
            'description' => $description !== null ? trim($description) : null,
        ]);

        $suggestion = $this->suggestionRepo->findById($id);
        return $this->formatSuggestion($suggestion, $userId);
    }

    public function deleteSuggestion(string $id, string $userId, bool $isAdmin): void
    {
        $suggestion = $this->suggestionRepo->findById($id);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $upvoteCount = $this->suggestionRepo->getUpvoteCount($id);
        if (!$isAdmin && ($suggestion['userId'] !== $userId || $upvoteCount >= 3)) {
            throw new \RuntimeException('forbidden');
        }

        $this->suggestionRepo->delete($id);
    }

    public function vote(string $suggestionId, string $userId): void
    {
        $suggestion = $this->suggestionRepo->findById($suggestionId);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $existing = $this->voteRepo->findBySuggestionAndUser($suggestionId, $userId);
        if ($existing !== null) {
            throw new \RuntimeException('already_voted');
        }

        $this->voteRepo->create($suggestionId, $userId);
    }

    public function removeVote(string $suggestionId, string $userId): void
    {
        $suggestion = $this->suggestionRepo->findById($suggestionId);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $this->voteRepo->delete($suggestionId, $userId);
    }

    public function updateStatus(string $id, string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \RuntimeException('invalid_status');
        }

        $suggestion = $this->suggestionRepo->findById($id);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $this->suggestionRepo->updateStatus($id, $status);
    }

    private function formatSuggestion(array $s, ?string $userId = null): array
    {
        $result = [
            'id' => $s['id'],
            'userId' => $s['userId'],
            'title' => $s['title'],
            'description' => $s['description'],
            'status' => $s['status'],
            'upvoteCount' => (int) $s['upvoteCount'],
            'createdAt' => $s['createdAt'],
            'updatedAt' => $s['updatedAt'],
        ];

        if (isset($s['hasVoted'])) {
            $result['hasVoted'] = (bool) $s['hasVoted'];
        } elseif ($userId !== null) {
            $vote = $this->voteRepo->findBySuggestionAndUser($s['id'], $userId);
            $result['hasVoted'] = $vote !== null;
        }

        return $result;
    }
}
