<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\PollAvailabilityVoteRepository;
use Sinclear\Api\Repository\PollInviteRepository;
use Sinclear\Api\Repository\PollResponseRepository;
use Sinclear\Api\Repository\PollVoteRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Support\PollAnonymity;

/**
 * Zentrale Empfängerlogik und Versand der Poll-Benachrichtigungen.
 */
final readonly class PollNotificationService
{
    public function __construct(
        private NotificationService $notificationService,
        private PollInviteRepository $inviteRepo,
        private PollResponseRepository $responseRepo,
        private PollAvailabilityVoteRepository $availabilityRepo,
        private PollVoteRepository $voteRepo,
        private PollAnonymity $anonymity,
        private ?UserRepository $userRepo = null,
    ) {}

    /** @param array<string, mixed> $poll */
    public function notifyInvite(array $poll, string $inviterId, string $inviteeId): void
    {
        if ($inviterId === $inviteeId) {
            return;
        }

        $pollTitle = trim((string) ($poll['title'] ?? ''));
        $inviter = $this->userRepo?->findById($inviterId);
        $inviterName = trim((string) ($inviter['displayName'] ?? ''));

        $body = match (true) {
            $pollTitle !== '' && $inviterName !== '' =>
                $inviterName . ' hat dich zur Umfrage „' . $pollTitle . '“ eingeladen.',
            $pollTitle !== '' =>
                'Du wurdest zur Umfrage „' . $pollTitle . '“ eingeladen.',
            default => '',
        };

        $this->notificationService->create(
            userId: $inviteeId,
            type: 'poll_invite',
            title: '',
            body: $body,
            data: [
                ['relation' => 'poll', 'object' => 'Poll', 'identifier' => (string) $poll['id']],
                ['relation' => 'inviter', 'object' => 'User', 'identifier' => $inviterId],
            ],
        );
    }

    /**
     * Gegenvorschlag: an Ersteller und übrige Teilnehmer (außer dem Vorschlagenden).
     *
     * @param array<string, mixed> $poll
     * @param array<string, mixed> $option
     */
    public function notifyCounterProposal(array $poll, string $proposerId, array $option): void
    {
        $recipients = array_unique([...$this->participantIds($poll), (string) $poll['creatorId']]);

        $pollTitle = trim((string) ($poll['title'] ?? ''));
        $proposer = $this->userRepo?->findById($proposerId);
        $proposerName = trim((string) ($proposer['displayName'] ?? ''));

        $body = match (true) {
            $pollTitle !== '' && $proposerName !== '' =>
                $proposerName . ' hat einen Gegenvorschlag zur Umfrage „' . $pollTitle . '“ gemacht.',
            $pollTitle !== '' =>
                'Neuer Gegenvorschlag zur Umfrage „' . $pollTitle . '“.',
            default => '',
        };

        foreach ($recipients as $recipientId) {
            if ($recipientId === $proposerId) {
                continue;
            }
            $this->notificationService->create(
                userId: $recipientId,
                type: 'poll_counter_proposal',
                title: '',
                body: $body,
                data: [
                    ['relation' => 'poll', 'object' => 'Poll', 'identifier' => (string) $poll['id']],
                    ['relation' => 'proposer', 'object' => 'User', 'identifier' => $proposerId],
                    ['relation' => 'option', 'object' => 'PollOption', 'identifier' => (string) $option['id']],
                ],
            );
        }
    }

    /**
     * Termin festgelegt oder Umfrage geschlossen.
     *
     * @param array<string, mixed> $poll
     * @param array<string, mixed>|null $option
     */
    public function notifyFinalized(array $poll, ?array $option = null, ?string $actorId = null): void
    {
        $data = [['relation' => 'poll', 'object' => 'Poll', 'identifier' => (string) $poll['id']]];
        if ($option !== null) {
            $data[] = ['relation' => 'finalized_option', 'object' => 'PollOption', 'identifier' => (string) $option['id']];
        }

        $pollTitle = trim((string) ($poll['title'] ?? ''));
        $optionLabel = $option !== null ? trim((string) ($option['label'] ?? '')) : '';

        $body = match (true) {
            $pollTitle !== '' && $optionLabel !== '' =>
                'Der Termin für „' . $pollTitle . '“ steht fest: ' . $optionLabel,
            $pollTitle !== '' =>
                'Die Umfrage „' . $pollTitle . '“ wurde abgeschlossen.',
            default => '',
        };

        foreach ($this->participantIds($poll) as $recipientId) {
            if ($recipientId === ($actorId ?? (string) $poll['creatorId'])) {
                continue;
            }
            $this->notificationService->create(
                userId: $recipientId,
                type: 'poll_finalized',
                title: '',
                body: $body,
                data: $data,
            );
        }
    }

    /** @param array<string, mixed> $poll */
    public function notifyClosed(array $poll, ?string $actorId = null): void
    {
        $this->notifyFinalized($poll, null, $actorId);
    }

    /**
     * Deadline-Erinnerung an Teilnehmer ohne Antwort/Stimme (dedupliziert).
     *
     * @param array<string, mixed> $poll
     */
    public function notifyDeadlineReminder(array $poll): void
    {
        $pollId = (string) $poll['id'];
        $type = (string) $poll['type'];
        $pollTitle = trim((string) ($poll['title'] ?? ''));
        $body = $pollTitle !== ''
            ? 'Die Umfrage „' . $pollTitle . '“ endet bald.'
            : '';

        foreach ($this->participantIds($poll) as $recipientId) {
            if ($recipientId === (string) $poll['creatorId']) {
                continue;
            }
            if ($this->hasParticipated($pollId, $type, $recipientId)) {
                continue;
            }

            $this->notificationService->create(
                userId: $recipientId,
                type: 'poll_deadline_reminder',
                title: '',
                body: $body,
                data: [['relation' => 'poll', 'object' => 'Poll', 'identifier' => $pollId]],
                dedupeKey: 'poll:' . $pollId . ':deadline',
            );
        }
    }

    /**
     * Alle bekannten Teilnehmer: Einladungen + Formular-Antworten +
     * Verfügbarkeitsstimmen. Anonyme Stimmen werden bewusst NICHT
     * aufgelöst; für `vote`-Polls kommen die Teilnehmer aus den Einladungen.
     *
     * @param array<string, mixed> $poll
     * @return string[]
     */
    private function participantIds(array $poll): array
    {
        $pollId = (string) $poll['id'];
        $ids = $this->inviteRepo->listInvitedUserIds($pollId);

        foreach ($this->responseRepo->listByPoll($pollId) as $row) {
            $ids[] = (string) $row['userId'];
        }
        foreach ($this->availabilityRepo->listUserIdsWithVotes($pollId) as $userId) {
            $ids[] = (string) $userId;
        }

        return array_values(array_unique($ids));
    }

    private function hasParticipated(string $pollId, string $type, string $userId): bool
    {
        if ($type === 'form') {
            return $this->responseRepo->findByPollAndUser($pollId, $userId) !== null;
        }
        if ($type === 'appointment') {
            return $this->availabilityRepo->listByPollAndUser($pollId, $userId) !== [];
        }
        if ($type === 'vote') {
            return $this->voteRepo->hasVoted($pollId, $this->anonymity->participantHash($pollId, $userId));
        }

        return false;
    }
}
