<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;

final readonly class TravelService
{
    public function __construct(
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private TravelRelationRepository $relationRepo,
    ) {}

    public function listTrips(string $userId, int $page, int $limit): array
    {
        return $this->tripRepo->findByParticipant($userId, $page, $limit);
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

        return $trip;
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
