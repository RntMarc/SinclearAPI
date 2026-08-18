<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class DirectMessagePolicy
{
    public function __construct(
        private ChatParticipantRepository $participantRepo,
    ) {}

    /**
     * Only participants of the conversation may access it.
     */
    public function canAccess(AuthenticatedUser $user, string $conversationId): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $this->participantRepo->isParticipant($conversationId, $user->id);
    }

    /**
     * Only the sender may edit their own message.
     */
    public function canEdit(AuthenticatedUser $user, array $message): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $message['senderId'];
    }

    /**
     * Only the sender may delete their own message (for all, no time window).
     */
    public function canDelete(AuthenticatedUser $user, array $message): bool
    {
        if ($user->isAdmin) {
            return true;
        }

        return $user->id === $message['senderId'];
    }
}
