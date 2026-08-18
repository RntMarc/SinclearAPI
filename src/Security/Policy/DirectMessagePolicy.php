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
}
