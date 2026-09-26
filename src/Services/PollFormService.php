<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\PollAnswerRepository;
use Sinclear\Api\Repository\PollOptionRepository;
use Sinclear\Api\Repository\PollQuestionRepository;
use Sinclear\Api\Repository\PollResponseRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;
use Sinclear\Api\Services\Poll\PollAnswerValidator;

/**
 * Formular-Umfragen: Antworten abgeben, ändern und auswerten.
 */
final readonly class PollFormService
{
    public function __construct(
        private PollService $pollService,
        private PollResponseRepository $responseRepo,
        private PollAnswerRepository $answerRepo,
        private PollQuestionRepository $questionRepo,
        private PollOptionRepository $optionRepo,
        private PollPolicy $policy,
        private PollAnswerValidator $answerValidator,
    ) {}

    /**
     * @param array<int, mixed> $answers Liste aus `{questionId, value}`
     */
    public function submit(AuthenticatedUser $user, string $pollId, array $answers): array
    {
        $poll = $this->pollService->loadPollOrFail($pollId);
        if (($poll['type'] ?? '') !== 'form') {
            throw new \RuntimeException('invalid_type');
        }

        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canRespond($user, $poll, $isInvited)) {
            throw new \RuntimeException($poll['status'] === 'closed' ? 'poll_closed' : 'forbidden');
        }

        $existing = $this->responseRepo->findByPollAndUser($pollId, $user->id);
        if ($existing !== null && ($poll['submissionMode'] ?? 'single') === 'single') {
            throw new \RuntimeException('already_responded');
        }

        $normalized = $this->validateAnswers($pollId, $answers);

        $responseId = $this->responseRepo->create($pollId, $user->id);
        foreach ($normalized as $questionId => $value) {
            $this->answerRepo->create($responseId, $questionId, $value);
        }

        return $this->formatResponse($this->responseRepo->findById($responseId));
    }

    public function getMine(AuthenticatedUser $user, string $pollId): ?array
    {
        $this->pollService->get($user, $pollId);
        $response = $this->responseRepo->findByPollAndUser($pollId, $user->id);
        if ($response === null) {
            return null;
        }

        return $this->formatResponse($response);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listResponses(AuthenticatedUser $user, string $pollId): array
    {
        $poll = $this->pollService->loadPollOrFail($pollId);
        if (($poll['type'] ?? '') !== 'form') {
            throw new \RuntimeException('invalid_type');
        }
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canSeeResults($user, $poll, $isInvited)) {
            throw new \RuntimeException('results_hidden');
        }

        $responses = $this->responseRepo->listByPoll($pollId);
        $data = array_map(fn(array $row) => $this->formatResponse($row), $responses);

        return [
            'data' => $data,
            'meta' => ['total' => count($data)],
        ];
    }

    /**
     * @param array<int, mixed> $answers
     */
    public function updateResponse(AuthenticatedUser $user, string $pollId, string $responseId, array $answers): array
    {
        $poll = $this->pollService->loadPollOrFail($pollId);
        if (($poll['type'] ?? '') !== 'form') {
            throw new \RuntimeException('invalid_type');
        }

        $response = $this->responseRepo->findById($responseId);
        if ($response === null || $response['pollId'] !== $pollId) {
            throw new \RuntimeException('not_found');
        }

        if (!$this->policy->canEditResponse($user, $poll, (string) $response['userId'])) {
            throw new \RuntimeException($poll['status'] === 'closed' ? 'poll_closed' : 'forbidden');
        }

        $normalized = $this->validateAnswers($pollId, $answers);

        $this->answerRepo->deleteByResponse($responseId);
        foreach ($normalized as $questionId => $value) {
            $this->answerRepo->create($responseId, $questionId, $value);
        }
        $this->responseRepo->touch($responseId);

        return $this->formatResponse($this->responseRepo->findById($responseId));
    }

    /**
     * Validiert alle Antworten gegen die Fragen der Umfrage.
     *
     * @param array<int, mixed> $answers
     * @return array<string, string|null> questionId => storage value
     */
    private function validateAnswers(string $pollId, array $answers): array
    {
        if ($answers !== [] && !array_is_list($answers)) {
            $list = [];
            foreach ($answers as $questionId => $value) {
                $list[] = ['questionId' => $questionId, 'value' => $value];
            }
            $answers = $list;
        }

        $questions = $this->questionRepo->listByPoll($pollId);
        $questionsById = [];
        $optionIdsByQuestion = [];
        foreach ($questions as $question) {
            $questionsById[(string) $question['id']] = $question;
            $optionIdsByQuestion[(string) $question['id']] = [];
        }
        foreach ($this->optionRepo->listByPoll($pollId) as $option) {
            $questionId = (string) ($option['questionId'] ?? '');
            if ($questionId !== '' && isset($optionIdsByQuestion[$questionId])) {
                $optionIdsByQuestion[$questionId][] = (string) $option['id'];
            }
        }

        $submitted = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                throw new \RuntimeException('invalid_answer');
            }
            $questionId = isset($answer['questionId']) ? (string) $answer['questionId'] : '';
            if ($questionId === '' || !isset($questionsById[$questionId])) {
                throw new \RuntimeException('invalid_answer');
            }
            $submitted[$questionId] = $answer['value'] ?? null;
        }

        $normalized = [];
        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $value = $submitted[$questionId] ?? null;
            $normalized[$questionId] = $this->answerValidator->validate(
                $question,
                $value,
                $optionIdsByQuestion[$questionId] ?? [],
            );
        }

        return $normalized;
    }

    /** @param array<string, mixed>|null $row */
    private function formatResponse(?array $row): array
    {
        if ($row === null) {
            throw new \RuntimeException('not_found');
        }

        $answers = [];
        foreach ($this->answerRepo->listByResponse((string) $row['id']) as $answer) {
            $answers[$answer['questionId']] = $answer['value'];
        }

        return [
            'id' => $row['id'],
            'pollId' => $row['pollId'],
            'userId' => $row['userId'],
            'userDisplayName' => $row['userDisplayName'] ?? null,
            'userImage' => $row['userImage'] ?? null,
            'answers' => $answers,
            'createdAt' => $row['createdAt'],
            'updatedAt' => $row['updatedAt'],
        ];
    }
}
