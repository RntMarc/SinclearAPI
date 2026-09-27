<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\PollAvailabilityVoteRepository;
use Sinclear\Api\Repository\PollOptionRepository;
use Sinclear\Api\Repository\PollRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;

/**
 * Terminfindung: Verfügbarkeiten, Gegenvorschläge und Finalisierung.
 */
final readonly class PollAppointmentService
{
    private const array AVAILABILITIES = ['yes', 'maybe', 'no'];

    public function __construct(
        private PollService $pollService,
        private PollRepository $pollRepo,
        private PollOptionRepository $optionRepo,
        private PollAvailabilityVoteRepository $availabilityRepo,
        private PollNotificationService $notificationService,
        private PollPolicy $policy,
    ) {}

    public function addCounterProposal(AuthenticatedUser $user, string $pollId, array $payload): array
    {
        $poll = $this->loadAppointment($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canAddCounterProposal($user, $poll, $isInvited)) {
            throw new \RuntimeException(
                (int) ($poll['allowCounterProposals'] ?? 0) !== 1 ? 'counter_proposals_disabled' : 'forbidden',
            );
        }

        $timing = $this->pollService->normalizeTimingFields($payload);
        $label = isset($payload['label']) ? trim((string) $payload['label']) : null;

        $position = count($this->optionRepo->listByPoll($pollId));
        $optionId = $this->optionRepo->create([
            'pollId' => $pollId,
            'label' => $label !== '' ? $label : null,
            'allDay' => $timing['allDay'],
            'timezone' => $timing['timezone'],
            'startAt' => $timing['startAt'],
            'endAt' => $timing['endAt'],
            'startDate' => $timing['startDate'],
            'endDate' => $timing['endDate'],
            'isCounterProposal' => 1,
            'proposedBy' => $user->id,
            'position' => $position,
        ]);

        $option = $this->optionRepo->findById($optionId);
        if ($option === null) {
            throw new \RuntimeException('not_found');
        }

        $this->notificationService->notifyCounterProposal($poll, $user->id, $option);

        return $this->pollService->formatOption($option);
    }

    /**
     * Löscht eine Terminoption. Ersteller/Admin dürfen jede Option entfernen
     * (eigene Vorschläge und Gegenvorschläge), andere Nutzer nur ihren eigenen
     * Gegenvorschlag.
     */
    public function removeOption(AuthenticatedUser $user, string $pollId, string $optionId): void
    {
        $poll = $this->loadAppointment($pollId);
        $option = $this->optionRepo->findById($optionId);
        if ($option === null || $option['pollId'] !== $pollId) {
            throw new \RuntimeException('option_not_found');
        }
        if (!$this->policy->canManage($user, $poll)
            && ((int) $option['isCounterProposal'] !== 1 || $option['proposedBy'] !== $user->id)
        ) {
            throw new \RuntimeException('forbidden');
        }

        $this->availabilityRepo->deleteByOption($optionId);
        $this->optionRepo->delete($optionId);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listAvailability(AuthenticatedUser $user, string $pollId): array
    {
        $poll = $this->loadAppointment($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canView($user, $poll, $isInvited)) {
            throw new \RuntimeException('forbidden');
        }

        $votes = array_map(
            static fn(array $row) => [
                'id' => $row['id'],
                'optionId' => $row['optionId'],
                'userId' => $row['userId'],
                'userDisplayName' => $row['userDisplayName'] ?? null,
                'userImage' => $row['userImage'] ?? null,
                'availability' => $row['availability'],
                'updatedAt' => $row['updatedAt'],
            ],
            $this->availabilityRepo->listByPoll($pollId),
        );

        return ['data' => $votes, 'meta' => ['total' => count($votes)]];
    }

    /**
     * @param array<int, mixed> $availability Liste aus `{optionId, availability}`
     * @return array<int, array<string, mixed>>
     */
    public function setAvailability(AuthenticatedUser $user, string $pollId, array $availability): array
    {
        $poll = $this->loadAppointment($pollId);
        $isInvited = $this->pollService->isInvited($pollId, $user->id);
        if (!$this->policy->canVote($user, $poll, $isInvited)) {
            throw new \RuntimeException($poll['status'] === 'closed' ? 'poll_closed' : 'forbidden');
        }

        $validOptionIds = [];
        foreach ($this->optionRepo->listByPoll($pollId) as $option) {
            $validOptionIds[] = (string) $option['id'];
        }

        $normalized = [];
        foreach ($availability as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('invalid_answer');
            }
            $optionId = isset($entry['optionId']) ? (string) $entry['optionId'] : '';
            $value = isset($entry['availability']) ? trim((string) $entry['availability']) : '';
            if (!in_array($optionId, $validOptionIds, true)) {
                throw new \RuntimeException('invalid_option');
            }
            if (!in_array($value, self::AVAILABILITIES, true)) {
                throw new \RuntimeException('invalid_answer');
            }
            $normalized[$optionId] = $value;
        }

        $this->availabilityRepo->replaceForUser($pollId, $user->id, $normalized);

        return array_values(array_map(
            static fn(array $row) => [
                'optionId' => $row['optionId'],
                'availability' => $row['availability'],
            ],
            $this->availabilityRepo->listByPollAndUser($pollId, $user->id),
        ));
    }

    public function finalize(AuthenticatedUser $user, string $pollId, string $optionId): array
    {
        $poll = $this->loadAppointment($pollId);
        if (!$this->policy->canFinalize($user, $poll)) {
            throw new \RuntimeException('forbidden');
        }

        $option = $this->optionRepo->findById($optionId);
        if ($option === null || $option['pollId'] !== $pollId) {
            throw new \RuntimeException('option_not_found');
        }

        $this->pollRepo->update($pollId, ['finalizedOptionId' => $optionId, 'status' => 'closed']);

        $updated = $this->pollService->loadPollOrFail($pollId);
        $this->notificationService->notifyFinalized($updated, $option, $user->id);

        return $this->pollService->get($user, $pollId);
    }

    private function loadAppointment(string $pollId): array
    {
        $poll = $this->pollService->loadPollOrFail($pollId);
        if (($poll['type'] ?? '') !== 'appointment') {
            throw new \RuntimeException('invalid_type');
        }
        return $poll;
    }
}
