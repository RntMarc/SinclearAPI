<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTicketRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\TravelTripSubscriptionRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class TravelService
{
    public function __construct(
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private TravelRelationRepository $relationRepo,
        private TravelTripSubscriptionRepository $tripSubscriptionRepo,
        private ForumRepository $forumRepo,
        private TravelTicketRepository $ticketRepo,
        private NotificationService $notificationService,
        private UserRepository $userRepo,
    ) {}

    public function listTrips(string $userId, int $page, int $limit): array
    {
        $result = $this->tripRepo->findByParticipant($userId, $page, $limit);
        $result['data'] = array_map(
            fn(array $t) => $this->enrichTrip($t),
            $result['data'],
        );
        return $result;
    }

    public function getTrip(string $id, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $id)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->enrichTrip($trip);
    }

    public function listEvents(string $tripId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $events = $this->eventRepo->findByTrip($tripId);
        return array_map(fn(array $e) => $this->enrichEvent($e), $events);
    }

    public function getEvent(string $tripId, string $eventId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $event = $this->eventRepo->findByIdAndTrip($eventId, $tripId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event);
    }

    public function listStandaloneEvents(string $userId, int $page, int $limit): array
    {
        $result = $this->eventRepo->findStandaloneByParticipant($userId, $page, $limit);
        $result['data'] = array_map(fn(array $e) => $this->enrichEvent($e), $result['data']);
        return $result;
    }

    public function getStandaloneEvent(string $eventId, string $userId): array
    {
        $event = $this->eventRepo->findStandaloneByIdAndParticipant($eventId, $userId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event);
    }

    public function listAccommodations(string $tripId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $accommodations = $this->accommodationRepo->findByTrip($tripId);
        return array_map(
            fn(array $a) => $this->enrichAccommodation($a, $tripId),
            $accommodations,
        );
    }

    public function getAccommodation(string $tripId, string $accommodationId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        $accommodation = $this->accommodationRepo->findByIdAndTrip($accommodationId, $tripId);
        if ($accommodation === null) {
            throw new \RuntimeException('Accommodation not found');
        }

        return $this->enrichAccommodation($accommodation, $tripId);
    }

    public function listParticipants(string $tripId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->relationRepo->findParticipantsByTrip($tripId);
    }

    public function getEventById(string $eventId, string $userId): array
    {
        $event = $this->eventRepo->findByIdWithAccess($eventId, $userId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return $this->enrichEvent($event);
    }

    public function getTripSubscriptions(string $tripId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return $this->tripSubscriptionRepo->findByTripWithUserAccess($tripId, $userId);
    }

    public function listTripTickets(string $tripId, string $userId): array
    {
        if (!$this->relationRepo->isParticipant($userId, $tripId)) {
            throw new \RuntimeException('Not a participant');
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Trip not found');
        }

        return array_merge(
            $this->ticketRepo->findByTrip($tripId),
            $this->ticketRepo->findByUserAndTrip($userId, $tripId),
        );
    }

    public function listEventTickets(string $eventId, string $userId): array
    {
        $event = $this->eventRepo->findByIdWithAccess($eventId, $userId);
        if ($event === null) {
            throw new \RuntimeException('Event not found');
        }

        return array_merge(
            $this->ticketRepo->findByEvent($eventId),
            $this->ticketRepo->findByUserAndEvent($userId, $eventId),
        );
    }

    public function listUserTickets(string $userId): array
    {
        return $this->ticketRepo->findByUser($userId);
    }

    public function createUserTicket(string $userId, array $data): array
    {
        $event = $data['event'] ?? null;
        $trip = $data['trip'] ?? null;

        if ($event !== null && $trip !== null) {
            throw new \RuntimeException('Not a participant');
        }

        $id = $this->ticketRepo->create([
            'type' => 'user',
            'title' => $data['title'] ?? null,
            'user' => $userId,
            'event' => $event,
            'trip' => $trip,
            'qrcode' => $data['qrcode'] ?? null,
            'image' => $data['image'] ?? null,
        ]);

        $ticket = $this->ticketRepo->findById($id);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket creation failed');
        }

        return $ticket;
    }

    public function updateUserTicket(string $ticketId, string $userId, array $data): array
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        if ($ticket['type'] !== 'user' || ($ticket['user'] ?? null) !== $userId) {
            throw new \RuntimeException('Not a participant');
        }

        $event = $data['event'] ?? null;
        $trip = $data['trip'] ?? null;

        if ($event !== null && $trip !== null) {
            throw new \RuntimeException('Not a participant');
        }

        $update = [];
        if (array_key_exists('title', $data)) {
            $update['title'] = $data['title'];
        }
        if (array_key_exists('qrcode', $data)) {
            $update['qrcode'] = $data['qrcode'];
        }
        if (array_key_exists('image', $data)) {
            $update['image'] = $data['image'];
        }
        if (array_key_exists('event', $data)) {
            $update['event'] = $data['event'];
        }
        if (array_key_exists('trip', $data)) {
            $update['trip'] = $data['trip'];
        }

        if ($update !== []) {
            $this->ticketRepo->update($ticketId, $update);
        }

        $updated = $this->ticketRepo->findById($ticketId);
        if ($updated === null) {
            throw new \RuntimeException('Ticket not found after update');
        }

        return $updated;
    }

    public function deleteUserTicket(string $ticketId, string $userId): void
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        if ($ticket['type'] !== 'user' || ($ticket['user'] ?? null) !== $userId) {
            throw new \RuntimeException('Not a participant');
        }

        $this->ticketRepo->delete($ticketId);
    }

    private function enrichTrip(array $trip): array
    {
        $forumId = $trip['forumId'] ?? null;
        if ($forumId !== null) {
            $forum = $this->forumRepo->findById($forumId);
            if ($forum === null) {
                $forumId = null;
            }
        }

        $trip['forumId'] = $forumId;

        if ($forumId !== null && isset($forum)) {
            $trip['forum'] = [
                'id' => $forum['id'],
                'name' => $forum['name'],
                'description' => $forum['description'],
                'image' => $forum['image'],
            ];
        } else {
            $trip['forum'] = null;
        }

        $trip['subscriptionCount'] = $this->tripSubscriptionRepo->countByTrip($trip['id']);

        return $trip;
    }

    private function enrichEvent(array $event): array
    {
        $event['participants'] = $this->eventRepo->findParticipantsByEvent($event['ID']);
        return $event;
    }

    private function enrichAccommodation(array $accommodation, string $tripId): array
    {
        $accommodation['users'] = $this->accommodationRepo->findUsersByAccommodation(
            $accommodation['ID'],
            $tripId,
        );
        return $accommodation;
    }
}
