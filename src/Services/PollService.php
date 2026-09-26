<?php

namespace Sinclear\Api\Services;

use PDO;
use Sinclear\Api\Repository\PollInviteRepository;
use Sinclear\Api\Repository\PollOptionRepository;
use Sinclear\Api\Repository\PollQuestionRepository;
use Sinclear\Api\Repository\PollRepository;
use Sinclear\Api\Repository\PollResponseRepository;
use Sinclear\Api\Repository\PollAvailabilityVoteRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;
use Sinclear\Api\Services\Poll\PollAnswerValidator;
use Sinclear\Api\Support\DateTimeValue;

final readonly class PollService
{
    public const array TYPES = ['form', 'appointment', 'vote'];

    public function __construct(
        private PDO $pdo,
        private PollRepository $pollRepo,
        private PollQuestionRepository $questionRepo,
        private PollOptionRepository $optionRepo,
        private PollInviteRepository $inviteRepo,
        private PollResponseRepository $responseRepo,
        private PollAvailabilityVoteRepository $availabilityRepo,
        private UserRepository $userRepo,
        private PollPolicy $policy,
        private PollAnswerValidator $answerValidator,
        private PollNotificationService $notificationService,
    ) {}

    public function create(AuthenticatedUser $user, array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException('invalid_type');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('title_required');
        }

        $description = isset($payload['description']) && is_string($payload['description'])
            ? trim($payload['description'])
            : null;

        $accessMode = $this->enumValue($payload['accessMode'] ?? 'invited', ['invited', 'all_users'], 'invalid_access_mode');
        $submissionMode = $this->enumValue($payload['submissionMode'] ?? 'single', ['single', 'multiple'], 'invalid_submission_mode');
        $resultsVisibility = $this->enumValue($payload['resultsVisibility'] ?? 'creator', ['creator', 'participants'], 'invalid_visibility');
        $closesAt = $this->parseClosesAt($payload['closesAt'] ?? null);
        $allowCounterProposals = $type === 'appointment' && (bool) ($payload['allowCounterProposals'] ?? false) ? 1 : 0;

        $inviteUserIds = $this->normalizeStringList($payload['inviteUserIds'] ?? []);

        $this->pdo->beginTransaction();
        try {
            $pollId = $this->pollRepo->create([
                'type' => $type,
                'creatorId' => $user->id,
                'title' => $title,
                'description' => $description,
                'accessMode' => $accessMode,
                'submissionMode' => $type === 'form' ? $submissionMode : 'single',
                'resultsVisibility' => $type === 'form' ? $resultsVisibility : 'creator',
                'allowCounterProposals' => $allowCounterProposals,
                'closesAt' => $closesAt,
            ]);

            if ($type === 'form') {
                $this->createFormQuestions($pollId, $payload['questions'] ?? []);
            } elseif ($type === 'appointment') {
                $this->createAppointmentOptions($pollId, $payload['options'] ?? []);
            } elseif ($type === 'vote') {
                $this->createVoteOptions($pollId, $payload['options'] ?? []);
            }

            foreach ($inviteUserIds as $inviteeId) {
                if ($inviteeId === $user->id) {
                    continue;
                }
                if ($this->inviteRepo->findByPollAndUser($pollId, $inviteeId) !== null) {
                    continue;
                }
                $this->inviteRepo->create($pollId, $inviteeId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $poll = $this->pollRepo->findById($pollId);
        if ($poll === null) {
            throw new \RuntimeException('not_found');
        }

        foreach ($inviteUserIds as $inviteeId) {
            if ($inviteeId === $user->id) {
                continue;
            }
            $this->notificationService->notifyInvite($poll, $user->id, $inviteeId);
        }

        return $this->buildDetail($user, $poll);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(AuthenticatedUser $user, array $filters, int $page, int $limit): array
    {
        $type = isset($filters['type']) && $filters['type'] !== '' ? (string) $filters['type'] : null;
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            throw new \RuntimeException('invalid_type');
        }
        $status = isset($filters['status']) && $filters['status'] !== '' ? (string) $filters['status'] : null;
        if ($status !== null && !in_array($status, ['open', 'closed'], true)) {
            throw new \RuntimeException('invalid_status');
        }

        $result = $this->pollRepo->listForUser($user->id, $type, $status, $page, $limit);
        $result['data'] = array_map(fn(array $row) => $this->formatPoll($row, $user), $result['data']);

        return $result;
    }

    public function get(AuthenticatedUser $user, string $id): array
    {
        $poll = $this->pollRepo->findById($id);
        if ($poll === null) {
            throw new \RuntimeException('not_found');
        }
        $isInvited = $this->isInvited($id, $user->id);
        if (!$this->policy->canView($user, $poll, $isInvited)) {
            throw new \RuntimeException('forbidden');
        }

        return $this->buildDetail($user, $poll, $isInvited);
    }

    public function update(AuthenticatedUser $user, string $id, array $payload): array
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $fields = [];
        if (array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                throw new \RuntimeException('title_required');
            }
            $fields['title'] = $title;
        }
        if (array_key_exists('description', $payload)) {
            $fields['description'] = $payload['description'] === null ? null : trim((string) $payload['description']);
        }
        if (array_key_exists('closesAt', $payload)) {
            $fields['closesAt'] = $this->parseClosesAt($payload['closesAt']);
        }
        if (array_key_exists('accessMode', $payload)) {
            $fields['accessMode'] = $this->enumValue($payload['accessMode'], ['invited', 'all_users'], 'invalid_access_mode');
        }
        if (array_key_exists('status', $payload) && $poll['type'] !== 'vote') {
            $fields['status'] = $this->enumValue($payload['status'], ['open', 'closed'], 'invalid_status');
        }
        if ($poll['type'] === 'form') {
            if (array_key_exists('submissionMode', $payload)) {
                $fields['submissionMode'] = $this->enumValue($payload['submissionMode'], ['single', 'multiple'], 'invalid_submission_mode');
            }
            if (array_key_exists('resultsVisibility', $payload)) {
                $fields['resultsVisibility'] = $this->enumValue($payload['resultsVisibility'], ['creator', 'participants'], 'invalid_visibility');
            }
        }
        if ($poll['type'] === 'appointment' && array_key_exists('allowCounterProposals', $payload)) {
            $fields['allowCounterProposals'] = (bool) $payload['allowCounterProposals'] ? 1 : 0;
        }

        $this->pollRepo->update($id, $fields);

        return $this->get($user, $id);
    }

    public function close(AuthenticatedUser $user, string $id): array
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $this->pollRepo->close($id);

        $updated = $this->pollRepo->findById($id);
        if ($updated !== null) {
            $this->notificationService->notifyClosed($updated, $user->id);
        }

        return $this->get($user, $id);
    }

    public function delete(AuthenticatedUser $user, string $id): void
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $this->pollRepo->delete($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function listInvites(AuthenticatedUser $user, string $id): array
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        return array_map(
            static fn(array $row) => [
                'id' => $row['id'],
                'pollId' => $row['pollId'],
                'userId' => $row['userId'],
                'userDisplayName' => $row['userDisplayName'] ?? null,
                'userImage' => $row['userImage'] ?? null,
                'isIndispensable' => (bool) $row['isIndispensable'],
                'createdAt' => $row['createdAt'],
            ],
            $this->inviteRepo->listByPoll($id),
        );
    }

    /**
     * @param string[] $userIds
     * @return array<int, array<string, mixed>>
     */
    public function addInvites(AuthenticatedUser $user, string $id, array $userIds): array
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $userIds = $this->normalizeStringList($userIds);
        foreach ($userIds as $inviteeId) {
            if ($inviteeId === $poll['creatorId']) {
                continue;
            }
            if ($this->userRepo->findById($inviteeId) === null) {
                throw new \RuntimeException('invalid_target');
            }
            if ($this->inviteRepo->findByPollAndUser($id, $inviteeId) !== null) {
                continue;
            }
            $this->inviteRepo->create($id, $inviteeId);
            $this->notificationService->notifyInvite($poll, $user->id, $inviteeId);
        }

        return $this->listInvites($user, $id);
    }

    public function removeInvite(AuthenticatedUser $user, string $id, string $userId): void
    {
        $poll = $this->loadPollOrFail($id);
        if (!$this->policy->canManage($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $this->inviteRepo->delete($id, $userId);
    }

    public function isInvited(string $pollId, string $userId): bool
    {
        return $this->inviteRepo->findByPollAndUser($pollId, $userId) !== null;
    }

    public function loadPollOrFail(string $id): array
    {
        $poll = $this->pollRepo->findById($id);
        if ($poll === null) {
            throw new \RuntimeException('not_found');
        }
        return $poll;
    }

    /**
     * Detailansicht inkl. Fragen/Optionen und eigener Teilnahmestatus.
     *
     * @param array<string, mixed> $poll
     */
    public function buildDetail(AuthenticatedUser $user, array $poll, ?bool $isInvited = null): array
    {
        $pollId = (string) $poll['id'];
        $isInvited ??= $this->isInvited($pollId, $user->id);

        $questions = [];
        foreach ($this->questionRepo->listByPoll($pollId) as $question) {
            $questions[] = $this->formatQuestion($question);
        }

        $options = [];
        foreach ($this->optionRepo->listByPoll($pollId) as $option) {
            $options[] = $this->formatOption($option);
        }

        $detail = $this->formatPoll($poll, $user);
        $detail['isInvited'] = $isInvited;
        $detail['questions'] = $questions;
        $detail['options'] = $options;
        $detail['participantStatus'] = [
            'hasResponded' => $this->responseRepo->findByPollAndUser($pollId, $user->id) !== null,
            'hasAvailability' => $this->availabilityRepo->listByPollAndUser($pollId, $user->id) !== [],
        ];

        return $detail;
    }

    /** @param array<string, mixed> $row */
    public function formatPoll(array $row, AuthenticatedUser $user): array
    {
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'creatorId' => $row['creatorId'],
            'creatorDisplayName' => $row['creatorDisplayName'] ?? null,
            'creatorImage' => $row['creatorImage'] ?? null,
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => $row['status'],
            'closesAt' => $this->formatInstant($row['closesAt'] ?? null),
            'accessMode' => $row['accessMode'],
            'submissionMode' => $row['submissionMode'],
            'resultsVisibility' => $row['resultsVisibility'],
            'allowCounterProposals' => (bool) $row['allowCounterProposals'],
            'finalizedOptionId' => $row['finalizedOptionId'],
            'isCreator' => $row['creatorId'] === $user->id,
            'createdAt' => $this->formatInstant($row['createdAt'] ?? null),
            'updatedAt' => $this->formatInstant($row['updatedAt'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    public function formatQuestion(array $row): array
    {
        $config = null;
        if (isset($row['config']) && is_string($row['config']) && $row['config'] !== '') {
            $decoded = json_decode($row['config'], true);
            $config = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => $row['id'],
            'pollId' => $row['pollId'],
            'type' => $row['type'],
            'title' => $row['title'],
            'description' => $row['description'],
            'isRequired' => (bool) $row['isRequired'],
            'position' => (int) $row['position'],
            'config' => $config,
        ];
    }

    /** @param array<string, mixed> $row */
    public function formatOption(array $row): array
    {
        $timing = DateTimeValue::normalizeTimingForOutput($row);

        return [
            'id' => $row['id'],
            'pollId' => $row['pollId'],
            'questionId' => $row['questionId'],
            'label' => $row['label'],
            'allDay' => $timing['allDay'],
            'timezone' => $timing['timezone'],
            'startAt' => $timing['startAt'] ?? null,
            'endAt' => $timing['endAt'] ?? null,
            'startDate' => $timing['startDate'] ?? null,
            'endDate' => $timing['endDate'] ?? null,
            'isCounterProposal' => (bool) $row['isCounterProposal'],
            'proposedBy' => $row['proposedBy'],
            'position' => (int) $row['position'],
            'createdAt' => $this->formatInstant($row['createdAt'] ?? null),
        ];
    }

    /** @param mixed $values */
    private function createFormQuestions(string $pollId, mixed $values): void
    {
        if (!is_array($values)) {
            throw new \RuntimeException('invalid_config');
        }

        $position = 0;
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException('invalid_config');
            }
            $type = trim((string) ($value['type'] ?? ''));
            if (!$this->answerValidator->supports($type)) {
                throw new \RuntimeException('invalid_config');
            }
            if (array_key_exists('config', $value) && $value['config'] !== null && !is_array($value['config'])) {
                throw new \RuntimeException('invalid_config');
            }

            $title = trim((string) ($value['title'] ?? ''));
            if ($title === '') {
                throw new \RuntimeException('invalid_config');
            }

            $questionId = $this->questionRepo->create([
                'pollId' => $pollId,
                'type' => $type,
                'title' => $title,
                'description' => isset($value['description']) ? trim((string) $value['description']) : null,
                'isRequired' => (bool) ($value['isRequired'] ?? false),
                'position' => $position++,
                'config' => is_array($value['config'] ?? null) ? $value['config'] : null,
            ]);

            if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
                $optionPosition = 0;
                foreach ((array) ($value['options'] ?? []) as $option) {
                    $label = is_array($option) ? trim((string) ($option['label'] ?? '')) : trim((string) $option);
                    if ($label === '') {
                        continue;
                    }
                    $this->optionRepo->create([
                        'pollId' => $pollId,
                        'questionId' => $questionId,
                        'label' => $label,
                        'position' => $optionPosition++,
                    ]);
                }
            }
        }
    }

    /** @param mixed $values */
    private function createAppointmentOptions(string $pollId, mixed $values): void
    {
        if (!is_array($values) || $values === []) {
            throw new \RuntimeException('invalid_config');
        }

        $position = 0;
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException('invalid_config');
            }
            $timing = $this->normalizeTimingFields($value);
            $label = isset($value['label']) ? trim((string) $value['label']) : null;

            $this->optionRepo->create([
                'pollId' => $pollId,
                'label' => $label !== '' ? $label : null,
                'allDay' => $timing['allDay'],
                'timezone' => $timing['timezone'],
                'startAt' => $timing['startAt'],
                'endAt' => $timing['endAt'],
                'startDate' => $timing['startDate'],
                'endDate' => $timing['endDate'],
                'position' => $position++,
            ]);
        }
    }

    /** @param mixed $values */
    private function createVoteOptions(string $pollId, mixed $values): void
    {
        if (!is_array($values) || $values === []) {
            throw new \RuntimeException('invalid_config');
        }

        $position = 0;
        foreach ($values as $value) {
            $label = is_array($value) ? trim((string) ($value['label'] ?? '')) : trim((string) $value);
            if ($label === '') {
                continue;
            }
            $this->optionRepo->create([
                'pollId' => $pollId,
                'label' => $label,
                'position' => $position++,
            ]);
        }

        if ($position === 0) {
            throw new \RuntimeException('invalid_config');
        }
    }

    /**
     * Normalisiert die Zeitfelder einer PollOption (AGENTS Date/Time Convention).
     *
     * @param array<string, mixed> $value
     * @return array{allDay: int, timezone: string, startAt: ?string, endAt: ?string, startDate: ?string, endDate: ?string}
     */
    public function normalizeTimingFields(array $value): array
    {
        $allDay = (bool) ($value['allDay'] ?? false);
        $timezone = DateTimeValue::normalizeTimeZone(isset($value['timezone']) ? (string) $value['timezone'] : null);

        if ($allDay) {
            if (isset($value['startAt']) || isset($value['endAt'])) {
                throw new \RuntimeException('time_forbidden');
            }
            $startDate = isset($value['startDate']) ? (string) $value['startDate'] : null;
            $endDate = isset($value['endDate']) ? (string) $value['endDate'] : null;
            if ($startDate === null || $endDate === null) {
                throw new \RuntimeException('invalid_config');
            }
            return [
                'allDay' => 1,
                'timezone' => $timezone,
                'startAt' => null,
                'endAt' => null,
                'startDate' => DateTimeValue::formatDate(DateTimeValue::parseDate($startDate)),
                'endDate' => DateTimeValue::formatDate(DateTimeValue::parseDate($endDate)),
            ];
        }

        if (isset($value['startDate']) || isset($value['endDate'])) {
            throw new \RuntimeException('date_forbidden');
        }
        $startAtRaw = (string) ($value['startAt'] ?? '');
        $endAtRaw = (string) ($value['endAt'] ?? '');
        $startAt = DateTimeValue::parseInstant($startAtRaw);
        $endAt = DateTimeValue::parseInstant($endAtRaw);

        return [
            'allDay' => 0,
            'timezone' => $timezone,
            'startAt' => DateTimeValue::toDatabase($startAt),
            'endAt' => DateTimeValue::toDatabase($endAt),
            'startDate' => null,
            'endDate' => null,
        ];
    }

    private function parseClosesAt(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }

        try {
            return DateTimeValue::toDatabase(DateTimeValue::parseInstant($value));
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('invalid_answer');
        }
    }

    private function formatInstant(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $instant = DateTimeValue::fromDatabase($value);
        if ($instant === null) {
            return null;
        }

        return DateTimeValue::formatInstant($instant, new \DateTimeZone('UTC'));
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }
            $result[] = trim($entry);
        }
        return array_values(array_unique($result));
    }

    /** @param string[] $allowed */
    private function enumValue(mixed $value, array $allowed, string $error): string
    {
        $value = trim((string) $value);
        if (!in_array($value, $allowed, true)) {
            throw new \RuntimeException($error);
        }
        return $value;
    }
}
