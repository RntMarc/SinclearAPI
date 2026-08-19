<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;

final readonly class TravelChatService
{
    public function __construct(
        private TravelChatRepository $travelChatRepo,
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelRelationRepository $travelRelationRepo,
        private EventRelationRepository $eventRelationRepo,
        private ChatConversationRepository $conversationRepo,
        private ChatParticipantRepository $participantRepo,
    ) {}

    /**
     * Create a group chat for a trip. Idempotent — returns existing if present.
     */
    public function createForTrip(string $tripId): array
    {
        $existing = $this->travelChatRepo->findByTripId($tripId);
        if ($existing !== null) {
            return $existing;
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $name = $trip['name'];
        $conversationId = $this->conversationRepo->create('group', $name);

        $id = $this->travelChatRepo->create($conversationId, $tripId, null);

        $this->syncTripMembersInternal($conversationId, $tripId);

        return $this->travelChatRepo->findById($id);
    }

    /**
     * Create a group chat for an event. Idempotent — returns existing if present.
     */
    public function createForEvent(string $eventId): array
    {
        $existing = $this->travelChatRepo->findByEventId($eventId);
        if ($existing !== null) {
            return $existing;
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        $name = $event['name'];
        $conversationId = $this->conversationRepo->create('group', $name);

        $id = $this->travelChatRepo->create($conversationId, null, $eventId);

        $this->syncEventMembersInternal($conversationId, $eventId);

        return $this->travelChatRepo->findById($id);
    }

    /**
     * Delete a travel chat for a trip.
     */
    public function deleteForTrip(string $tripId): void
    {
        $this->travelChatRepo->deleteByTripId($tripId);
    }

    /**
     * Delete a travel chat for an event.
     */
    public function deleteForEvent(string $eventId): void
    {
        $this->travelChatRepo->deleteByEventId($eventId);
    }

    /**
     * Sync chat participants with current trip participants.
     */
    public function syncTripMembers(string $tripId): void
    {
        $travelChat = $this->travelChatRepo->findByTripId($tripId);
        if ($travelChat === null) {
            return;
        }

        $this->syncTripMembersInternal($travelChat['conversationId'], $tripId);
    }

    /**
     * Sync chat participants with current event participants.
     */
    public function syncEventMembers(string $eventId): void
    {
        $travelChat = $this->travelChatRepo->findByEventId($eventId);
        if ($travelChat === null) {
            return;
        }

        $this->syncEventMembersInternal($travelChat['conversationId'], $eventId);
    }

    private function syncTripMembersInternal(string $conversationId, string $tripId): void
    {
        $currentParticipants = $this->travelRelationRepo->findParticipantsByTrip($tripId);
        $this->reconcileParticipants($conversationId, $currentParticipants, 'id');
    }

    private function syncEventMembersInternal(string $conversationId, string $eventId): void
    {
        $currentParticipants = $this->eventRelationRepo->findByEvent($eventId);
        $this->reconcileParticipants($conversationId, $currentParticipants, 'userId');
    }

    /**
     * Reconcile ChatParticipant rows with the given list of participants.
     * Adds new participants, removes stale ones.
     */
    private function reconcileParticipants(string $conversationId, array $participants, string $userIdKey): void
    {
        $currentIds = [];
        foreach ($participants as $p) {
            $currentIds[] = $p[$userIdKey];
        }
        $currentIds = array_unique($currentIds);

        // Add missing participants
        foreach ($currentIds as $userId) {
            $this->participantRepo->add($conversationId, $userId);
        }

        // Remove stale participants
        $existing = $this->participantRepo->findByConversation($conversationId);
        foreach ($existing as $existingParticipant) {
            if (!in_array($existingParticipant['userId'], $currentIds, true)) {
                $this->participantRepo->remove($conversationId, $existingParticipant['userId']);
            }
        }
    }
}
