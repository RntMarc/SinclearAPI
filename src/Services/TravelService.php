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

        return $this->eventRepo->findByTrip($tripId);
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

        return $event;
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

        return $this->accommodationRepo->findByTrip($tripId);
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

        return $accommodation;
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
}
