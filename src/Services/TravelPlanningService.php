<?php

namespace Sinclear\Api\Services;

use PDO;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelPlanAccommodationOptionRepository;
use Sinclear\Api\Repository\TravelPlanDateOptionRepository;
use Sinclear\Api\Repository\TravelPlanDateResponseRepository;
use Sinclear\Api\Repository\TravelPlanEventInterestRepository;
use Sinclear\Api\Repository\TravelPlanEventRepository;
use Sinclear\Api\Repository\TravelPlanMemberRepository;
use Sinclear\Api\Repository\TravelPlanTopicRepository;
use Sinclear\Api\Repository\TravelPlanTransportRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\TravelPlanningPolicy;
use Sinclear\Api\Support\DateTimeValue;
use Sinclear\Api\Support\TravelInput;

/**
 * Planungsreisen (TravelTrip.state = 'planning').
 *
 * Trennt Planungsdaten strikt von den operativen Travel-Objekten: Zugriff
 * ausschliesslich ueber TravelPlanMember. Der Zustand einer Reise wird nur
 * hier (Anlage) und in activatePlanningTrip() veraendert, niemals ueber einen
 * normalen Reise-PATCH.
 */
final readonly class TravelPlanningService
{
    private const MEMBER_STATUSES = ['accepted', 'inactive'];
    private const RESPONSES = ['accepted', 'declined'];
    private const AVAILABILITIES = ['yes', 'maybe', 'no'];
    private const INTERESTS = ['yes', 'maybe', 'no'];
    private const DIRECTIONS = ['outbound', 'return'];

    public function __construct(
        private TravelTripRepository $tripRepo,
        private TravelRelationRepository $relationRepo,
        private TravelEventRepository $eventRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private TravelPlanMemberRepository $memberRepo,
        private TravelPlanTopicRepository $topicRepo,
        private TravelPlanDateOptionRepository $dateOptionRepo,
        private TravelPlanDateResponseRepository $dateResponseRepo,
        private TravelPlanTransportRepository $transportRepo,
        private TravelPlanAccommodationOptionRepository $accommodationOptionRepo,
        private TravelPlanEventRepository $planEventRepo,
        private TravelPlanEventInterestRepository $eventInterestRepo,
        private TravelChatRepository $travelChatRepo,
        private UserRepository $userRepo,
        private TravelChatService $chatService,
        private TravelPlanningPolicy $policy,
        private TravelPlanningNotificationService $notificationService,
        private PDO $pdo,
    ) {}

    // ──────────────────────────── Lesen ────────────────────────────

    public function listPlanningTrips(AuthenticatedUser $user, int $page, int $limit): array
    {
        $result = $this->tripRepo->findPlanningByParticipant($user->id, $page, $limit);
        $result['data'] = array_map(
            fn(array $t) => $this->enrichPlanSummary($t, $user),
            $result['data'],
        );
        return $result;
    }

    public function getPlan(string $tripId, AuthenticatedUser $user): array
    {
        $trip = $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);

        $trip = $this->enrichPlanSummary($trip, $user);
        $trip['topics'] = $this->topicRepo->findByTrip($tripId);
        $trip['members'] = $this->memberRepo->findByTrip($tripId);
        $trip['dateOptions'] = $this->dateOptionsWithResponses($tripId);
        $trip['transport'] = $this->transportRepo->findByTrip($tripId);
        $trip['accommodationOptions'] = $this->accommodationOptionRepo->findByTrip($tripId);
        $trip['eventSuggestions'] = $this->eventSuggestionsWithInterests($tripId);

        $chat = $this->travelChatRepo->findByTripId($tripId);
        $trip['conversationId'] = $chat['conversationId'] ?? null;

        return $trip;
    }

    /** @return list<array<string, mixed>> */
    public function listMembers(string $tripId, AuthenticatedUser $user): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);
        return $this->memberRepo->findByTrip($tripId);
    }

    /** @return list<array<string, mixed>> */
    public function listDateOptions(string $tripId, AuthenticatedUser $user): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);
        return $this->dateOptionsWithResponses($tripId);
    }

    /** @return list<array<string, mixed>> */
    public function listTransport(string $tripId, AuthenticatedUser $user): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);
        return $this->transportRepo->findByTrip($tripId);
    }

    /** @return list<array<string, mixed>> */
    public function listAccommodationOptions(string $tripId, AuthenticatedUser $user): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);
        return $this->accommodationOptionRepo->findByTrip($tripId);
    }

    /** @return list<array<string, mixed>> */
    public function listEventSuggestions(string $tripId, AuthenticatedUser $user): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertCanView($tripId, $user);
        return $this->eventSuggestionsWithInterests($tripId);
    }

    // ──────────────────────────── Anlage ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createPlanningTrip(AuthenticatedUser $user, array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name required');
        }

        $skipped = $this->normalizeSkippedTopics($body['skippedTopics'] ?? null);

        $this->pdo->beginTransaction();
        try {
            $id = $this->tripRepo->create([
                'name' => $name,
                'description' => TravelInput::nullableString($body, 'description'),
                'state' => 'planning',
            ]);

            $this->memberRepo->create($id, $user->id, 'accepted', 'leader', 'creator');

            foreach (TravelPlanningPolicy::TOPICS as $topic) {
                $this->topicRepo->upsert(
                    $id,
                    $topic,
                    in_array($topic, $skipped, true) ? 'skipped' : 'pending',
                );
            }

            $this->chatService->createForPlanningTrip($id);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getPlan($id, $user);
    }

    /** @param array<string, mixed> $body */
    public function updatePlanningTrip(AuthenticatedUser $user, string $tripId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = TravelInput::nullableString($body, 'description');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->tripRepo->update($tripId, $data);

        return $this->getPlan($tripId, $user);
    }

    // ──────────────────────────── Mitglieder ────────────────────────────

    public function inviteMember(AuthenticatedUser $user, string $tripId, string $targetUserId): string
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        $targetUserId = trim($targetUserId);
        if ($targetUserId === '') {
            throw new \RuntimeException('UserId required');
        }
        if ($this->userRepo->findById($targetUserId) === null) {
            throw new \RuntimeException('User not found');
        }

        $existing = $this->memberRepo->findByTripAndUser($tripId, $targetUserId);
        if ($existing !== null && $existing['status'] !== 'inactive') {
            throw new \RuntimeException('Already a member');
        }

        $this->memberRepo->invite($tripId, $targetUserId);
        $member = $this->memberRepo->findByTripAndUser($tripId, $targetUserId);
        $id = $member['id'] ?? '';

        $this->chatService->syncPlanningMembers($tripId);
        $this->notificationService->notifyInvite($tripId, $targetUserId, $user->id);

        return $id;
    }

    public function setMemberStatus(AuthenticatedUser $user, string $tripId, string $targetUserId, string $status): void
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        if (!in_array($status, self::MEMBER_STATUSES, true)) {
            throw new \RuntimeException('Invalid member status');
        }

        $member = $this->memberRepo->findByTripAndUser($tripId, $targetUserId);
        if ($member === null) {
            throw new \RuntimeException('Member not found');
        }

        $this->assertNotLastLeader($tripId, $member, $status === 'inactive');

        $this->memberRepo->updateStatus($tripId, $targetUserId, $status);
        $this->chatService->syncPlanningMembers($tripId);
    }

    public function respondToInvitation(AuthenticatedUser $user, string $tripId, string $status): void
    {
        $this->requirePlanningTrip($tripId);
        $member = $this->assertActiveMember($tripId, $user, true);

        if (!in_array($status, self::RESPONSES, true)) {
            throw new \RuntimeException('Invalid response');
        }

        if ($member['status'] === $status) {
            return;
        }

        $this->memberRepo->updateStatus($tripId, $user->id, $status);
        $this->notificationService->notifyResponse($tripId, $user->id, $user->id);
    }

    public function removeMember(AuthenticatedUser $user, string $tripId, string $targetUserId): void
    {
        $this->requirePlanningTrip($tripId);

        if ($targetUserId === $user->id) {
            $member = $this->assertActiveMember($tripId, $user, true);
        } else {
            $this->assertLeader($tripId, $user);
            $member = $this->memberRepo->findByTripAndUser($tripId, $targetUserId);
            if ($member === null) {
                throw new \RuntimeException('Member not found');
            }
        }

        if ($member['status'] === 'inactive') {
            return;
        }

        $this->assertNotLastLeader($tripId, $member, true);

        $this->memberRepo->updateStatus($tripId, $targetUserId, 'inactive');
        $this->chatService->syncPlanningMembers($tripId);
    }

    // ──────────────────────────── Themen ────────────────────────────

    /** @return list<array<string, mixed>> */
    public function setTopicStatus(AuthenticatedUser $user, string $tripId, string $topic, string $status): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        if (!in_array($topic, TravelPlanningPolicy::TOPICS, true)) {
            throw new \RuntimeException('Invalid topic');
        }
        if (!in_array($status, TravelPlanningPolicy::TOPIC_STATUSES, true)) {
            throw new \RuntimeException('Invalid topic status');
        }

        $this->topicRepo->upsert($tripId, $topic, $status);

        return $this->topicRepo->findByTrip($tripId);
    }

    // ──────────────────────────── Terminoptionen ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createDateOption(AuthenticatedUser $user, string $tripId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        $timing = $this->timingOrFail($body, !array_key_exists('allDay', $body) || !empty($body['allDay']));

        $id = $this->dateOptionRepo->create(array_merge($timing, [
            'tripId' => $tripId,
            'label' => TravelInput::nullableString($body, 'label'),
            'proposedBy' => $user->id,
            'position' => count($this->dateOptionRepo->findByTrip($tripId)),
        ]));

        return $this->getDateOption($tripId, $id);
    }

    /** @param array<string, mixed> $body */
    public function updateDateOption(AuthenticatedUser $user, string $tripId, string $dateOptionId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $option = $this->dateOptionRepo->findByIdAndTrip($dateOptionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Date option not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $option['proposedBy']);

        $data = [];
        if (array_key_exists('label', $body)) {
            $data['label'] = TravelInput::nullableString($body, 'label');
        }
        if (TravelInput::hasTimingFields($body)) {
            $data = array_merge($data, $this->timingOrFail($body, (int) ($option['allDay'] ?? 1) === 1));
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->dateOptionRepo->update($dateOptionId, $data);

        return $this->getDateOption($tripId, $dateOptionId);
    }

    public function deleteDateOption(AuthenticatedUser $user, string $tripId, string $dateOptionId): void
    {
        $this->requirePlanningTrip($tripId);
        $option = $this->dateOptionRepo->findByIdAndTrip($dateOptionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Date option not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $option['proposedBy']);

        $this->dateOptionRepo->delete($dateOptionId);
    }

    public function setDateResponse(AuthenticatedUser $user, string $tripId, string $dateOptionId, string $availability): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        if (!in_array($availability, self::AVAILABILITIES, true)) {
            throw new \RuntimeException('Invalid availability');
        }
        if ($this->dateOptionRepo->findByIdAndTrip($dateOptionId, $tripId) === null) {
            throw new \RuntimeException('Date option not found');
        }

        $this->dateResponseRepo->upsert($dateOptionId, $user->id, $availability);

        return $this->getDateOption($tripId, $dateOptionId);
    }

    public function finalizeDateOption(AuthenticatedUser $user, string $tripId, string $dateOptionId): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        if ($this->dateOptionRepo->findByIdAndTrip($dateOptionId, $tripId) === null) {
            throw new \RuntimeException('Date option not found');
        }

        $this->dateOptionRepo->setFinalExclusive($tripId, $dateOptionId);

        $this->notificationService->notifyFinalized($tripId, [
            'relation' => 'date_option',
            'object' => 'TravelPlanDateOption',
            'identifier' => $dateOptionId,
        ], $user->id);

        return $this->getDateOption($tripId, $dateOptionId);
    }

    // ──────────────────────────── Transport ────────────────────────────

    /** @param array<string, mixed> $body */
    public function setTransport(AuthenticatedUser $user, string $tripId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        $direction = trim((string) ($body['direction'] ?? 'outbound'));
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new \RuntimeException('Invalid direction');
        }

        $data = [];
        if (array_key_exists('mode', $body)) {
            $data['mode'] = TravelInput::nullableString($body, 'mode');
        }
        if (array_key_exists('offersRide', $body)) {
            $data['offersRide'] = !empty($body['offersRide']) ? 1 : 0;
        }
        if (array_key_exists('availableSeats', $body)) {
            $data['availableSeats'] = TravelInput::nullableInt($body, 'availableSeats');
        }
        if (array_key_exists('notes', $body)) {
            $data['notes'] = TravelInput::nullableString($body, 'notes');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->transportRepo->upsert($tripId, $user->id, $direction, $data);

        return $this->transportRepo->findByTripAndUserAndDirection($tripId, $user->id, $direction) ?? [];
    }

    // ──────────────────────────── Unterkunftsoptionen ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createAccommodationOption(AuthenticatedUser $user, string $tripId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        $accommodationId = TravelInput::nullableString($body, 'accommodationId');
        $catalog = null;
        if ($accommodationId !== null) {
            $catalog = $this->accommodationRepo->findById($accommodationId);
            if ($catalog === null) {
                throw new \RuntimeException('Accommodation not found');
            }
        }

        $name = TravelInput::nullableString($body, 'name') ?? ($catalog['name'] ?? null);
        if ($name === null || trim((string) $name) === '') {
            throw new \RuntimeException('Name required');
        }

        $id = $this->accommodationOptionRepo->create([
            'tripId' => $tripId,
            'accommodationId' => $accommodationId,
            'name' => $name,
            'description' => TravelInput::nullableString($body, 'description') ?? ($catalog['description'] ?? null),
            'address' => TravelInput::nullableString($body, 'address') ?? ($catalog['address'] ?? null),
            'OSMID' => TravelInput::nullableInt($body, 'OSMID') ?? ($catalog['OSMID'] ?? null),
            'latitude' => TravelInput::nullableFloat($body, 'latitude') ?? ($catalog['latitude'] ?? null),
            'longitude' => TravelInput::nullableFloat($body, 'longitude') ?? ($catalog['longitude'] ?? null),
            'citySlug' => TravelInput::nullableString($body, 'citySlug') ?? ($catalog['citySlug'] ?? null),
            'pricePerPersonPerNight' => $this->priceFromBody($body),
            'currency' => TravelInput::nullableString($body, 'currency'),
            'proposedBy' => $user->id,
        ]);

        return $this->accommodationOptionRepo->findByIdAndTrip($id, $tripId) ?? [];
    }

    /** @param array<string, mixed> $body */
    public function updateAccommodationOption(AuthenticatedUser $user, string $tripId, string $optionId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $option = $this->accommodationOptionRepo->findByIdAndTrip($optionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Accommodation option not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $option['proposedBy']);

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        foreach (['description', 'address', 'citySlug'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = TravelInput::nullableString($body, $field);
            }
        }
        if (array_key_exists('OSMID', $body)) {
            $data['OSMID'] = TravelInput::nullableInt($body, 'OSMID');
        }
        if (array_key_exists('latitude', $body)) {
            $data['latitude'] = TravelInput::nullableFloat($body, 'latitude');
        }
        if (array_key_exists('longitude', $body)) {
            $data['longitude'] = TravelInput::nullableFloat($body, 'longitude');
        }
        if (array_key_exists('pricePerPersonPerNight', $body)) {
            $data['pricePerPersonPerNight'] = $this->priceFromBody($body);
        }
        if (array_key_exists('currency', $body)) {
            $data['currency'] = TravelInput::nullableString($body, 'currency');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->accommodationOptionRepo->update($optionId, $data);

        return $this->accommodationOptionRepo->findByIdAndTrip($optionId, $tripId) ?? [];
    }

    public function deleteAccommodationOption(AuthenticatedUser $user, string $tripId, string $optionId): void
    {
        $this->requirePlanningTrip($tripId);
        $option = $this->accommodationOptionRepo->findByIdAndTrip($optionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Accommodation option not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $option['proposedBy']);

        $this->accommodationOptionRepo->delete($optionId);
    }

    public function selectAccommodationOption(AuthenticatedUser $user, string $tripId, string $optionId): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        $option = $this->accommodationOptionRepo->findByIdAndTrip($optionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Accommodation option not found');
        }

        $this->accommodationOptionRepo->setSelectedExclusive($tripId, $optionId);

        $this->notificationService->notifyFinalized($tripId, [
            'relation' => 'accommodation_option',
            'object' => 'TravelPlanAccommodationOption',
            'identifier' => $optionId,
        ], $user->id);

        return $this->accommodationOptionRepo->findByIdAndTrip($optionId, $tripId) ?? [];
    }

    // ──────────────────────────── Eventvorschläge ────────────────────────────

    /** @param array<string, mixed> $body */
    public function createEventSuggestion(AuthenticatedUser $user, string $tripId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name required');
        }

        $timing = $this->timingOrFail($body, !empty($body['allDay']));

        $id = $this->planEventRepo->create(array_merge($timing, [
            'tripId' => $tripId,
            'name' => $name,
            'description' => TravelInput::nullableString($body, 'description'),
            'dayIndex' => TravelInput::nullableInt($body, 'dayIndex') ?? 0,
            'address' => TravelInput::nullableString($body, 'address'),
            'latitude' => TravelInput::nullableFloat($body, 'latitude'),
            'longitude' => TravelInput::nullableFloat($body, 'longitude'),
            'OSMID' => TravelInput::nullableInt($body, 'OSMID'),
            'citySlug' => TravelInput::nullableString($body, 'citySlug'),
            'proposedBy' => $user->id,
        ]));

        return $this->getEventSuggestion($tripId, $id);
    }

    /** @param array<string, mixed> $body */
    public function updateEventSuggestion(AuthenticatedUser $user, string $tripId, string $suggestionId, array $body): array
    {
        $this->requirePlanningTrip($tripId);
        $suggestion = $this->planEventRepo->findByIdAndTrip($suggestionId, $tripId);
        if ($suggestion === null) {
            throw new \RuntimeException('Event suggestion not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $suggestion['proposedBy']);

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('description', $body)) {
            $data['description'] = TravelInput::nullableString($body, 'description');
        }
        if (TravelInput::hasTimingFields($body)) {
            $data = array_merge($data, $this->timingOrFail($body, (int) ($suggestion['allDay'] ?? 0) === 1));
        }
        if (array_key_exists('dayIndex', $body)) {
            $data['dayIndex'] = TravelInput::nullableInt($body, 'dayIndex') ?? 0;
        }
        foreach (['address', 'citySlug'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = TravelInput::nullableString($body, $field);
            }
        }
        if (array_key_exists('latitude', $body)) {
            $data['latitude'] = TravelInput::nullableFloat($body, 'latitude');
        }
        if (array_key_exists('longitude', $body)) {
            $data['longitude'] = TravelInput::nullableFloat($body, 'longitude');
        }
        if (array_key_exists('OSMID', $body)) {
            $data['OSMID'] = TravelInput::nullableInt($body, 'OSMID');
        }

        if ($data === []) {
            throw new \RuntimeException('No fields to update');
        }

        $this->planEventRepo->update($suggestionId, $data);

        return $this->getEventSuggestion($tripId, $suggestionId);
    }

    public function deleteEventSuggestion(AuthenticatedUser $user, string $tripId, string $suggestionId): void
    {
        $this->requirePlanningTrip($tripId);
        $suggestion = $this->planEventRepo->findByIdAndTrip($suggestionId, $tripId);
        if ($suggestion === null) {
            throw new \RuntimeException('Event suggestion not found');
        }
        $this->assertCanManageSuggestion($tripId, $user, $suggestion['proposedBy']);

        $this->planEventRepo->delete($suggestionId);
    }

    public function setEventInterest(AuthenticatedUser $user, string $tripId, string $suggestionId, string $interest): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertActiveMember($tripId, $user, true);

        if (!in_array($interest, self::INTERESTS, true)) {
            throw new \RuntimeException('Invalid interest');
        }
        if ($this->planEventRepo->findByIdAndTrip($suggestionId, $tripId) === null) {
            throw new \RuntimeException('Event suggestion not found');
        }

        $this->eventInterestRepo->upsert($suggestionId, $user->id, $interest);

        return $this->getEventSuggestion($tripId, $suggestionId);
    }

    public function confirmEventSuggestion(AuthenticatedUser $user, string $tripId, string $suggestionId, bool $confirmed): array
    {
        $this->requirePlanningTrip($tripId);
        $this->assertLeader($tripId, $user);

        if ($this->planEventRepo->findByIdAndTrip($suggestionId, $tripId) === null) {
            throw new \RuntimeException('Event suggestion not found');
        }

        $this->planEventRepo->setConfirmation($suggestionId, $confirmed);

        if ($confirmed) {
            $this->notificationService->notifyFinalized($tripId, [
                'relation' => 'event',
                'object' => 'TravelPlanEvent',
                'identifier' => $suggestionId,
            ], $user->id);
        }

        return $this->getEventSuggestion($tripId, $suggestionId);
    }

    // ──────────────────────────── Aktivierung ────────────────────────────

    /**
     * Aktiviert eine Planungsreise: transaktional und idempotent. Bestaetigte
     * Mitglieder werden in TravelRelation uebernommen, die gewaehlte Unterkunft
     * (inkl. Preis) mit der Reise verknuepft, bestaetigte Eventvorschlaege in
     * TravelEvent umgewandelt und die Chat-Mitgliedschaft abgeglichen.
     *
     * @return array<string, mixed> aktualisierte (aktive) Reise
     */
    public function activatePlanningTrip(AuthenticatedUser $user, string $tripId): array
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Planning trip not found');
        }

        // Rechte IMMER pruefen – auch beim idempotenten Wiederholen einer
        // bereits aktiven Reise. Sonst koennte jedes eingeloggte Konto ueber
        // die Aktivierungsroute beliebige Reisedaten (Ticket, URL, ...) abrufen.
        $this->assertLeader($tripId, $user);

        $this->pdo->beginTransaction();
        try {
            // Zeilensperre serialisiert konkurrierende Aktivierungen: der
            // zweite Aufruf sieht den Zustand 'active' und wird zum No-op.
            $locked = $this->tripRepo->findByIdForUpdate($tripId);
            if ($locked === null) {
                throw new \RuntimeException('Planning trip not found');
            }

            $state = $locked['state'] ?? 'active';
            if ($state === 'active') {
                // Idempotent: wiederholter Aufruf erzeugt keine Duplikate.
                $this->pdo->commit();
                return $locked;
            }
            if ($state !== 'planning') {
                throw new \RuntimeException('Not a planning trip');
            }

            // Autorisierung gegen eine parallele Rollenaenderung absichern.
            $this->assertLeader($tripId, $user);

            $final = $this->dateOptionRepo->findFinalByTrip($tripId);
            if ($final !== null) {
                $this->tripRepo->update($tripId, [
                    'allDay' => (int) $final['allDay'],
                    'timezone' => $final['timezone'],
                    'startAt' => $final['startAt'],
                    'endAt' => $final['endAt'],
                    'startDate' => $final['startDate'],
                    'endDate' => $final['endDate'],
                ]);
            }

            $this->tripRepo->setState($tripId, 'active');

            $this->transferMembers($tripId);
            $this->transferAccommodation($tripId, $user);
            $this->transferEvents($tripId);

            $this->chatService->syncTripMembers($tripId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notificationService->notifyActivated($tripId, $user->id);

        return $this->tripRepo->findById($tripId) ?? [];
    }

    private function transferMembers(string $tripId): void
    {
        foreach ($this->memberRepo->findActiveByTrip($tripId) as $member) {
            $isLeader = ($member['role'] ?? null) === 'leader';
            if (!$isLeader && ($member['status'] ?? null) !== 'accepted') {
                continue;
            }

            $role = $isLeader ? 'leader' : 'participant';
            if ($this->relationRepo->isParticipant($member['userId'], $tripId)) {
                if ($isLeader) {
                    $this->relationRepo->updateRole($member['userId'], $tripId, 'leader');
                }
                continue;
            }

            $this->relationRepo->addParticipant($member['userId'], $tripId, null, $role);
        }
    }

    private function transferAccommodation(string $tripId, AuthenticatedUser $user): void
    {
        $selected = $this->accommodationOptionRepo->findSelectedByTrip($tripId);
        if ($selected === null) {
            return;
        }

        $accommodationId = $selected['accommodationId'] ?? null;
        if ($accommodationId === null || $this->accommodationRepo->findById($accommodationId) === null) {
            $accommodationId = $this->accommodationRepo->create([
                'name' => $selected['name'] ?? 'Unterkunft',
                'description' => $selected['description'] ?? null,
                'address' => $selected['address'] ?? null,
                'OSMID' => $selected['OSMID'] ?? null,
                'latitude' => $selected['latitude'] ?? null,
                'longitude' => $selected['longitude'] ?? null,
                'citySlug' => $selected['citySlug'] ?? null,
                'createdBy' => $user->id,
            ]);
        }

        $this->accommodationRepo->linkToTripWithPrice(
            $tripId,
            $accommodationId,
            $selected['pricePerPersonPerNight'] ?? null,
            $selected['currency'] ?? null,
        );
    }

    private function transferEvents(string $tripId): void
    {
        foreach ($this->planEventRepo->findConfirmedByTrip($tripId) as $suggestion) {
            if (($suggestion['confirmedEventId'] ?? null) !== null) {
                continue;
            }

            $eventId = $this->eventRepo->create([
                'trip' => $tripId,
                'name' => $suggestion['name'],
                'description' => $suggestion['description'] ?? null,
                'allDay' => (int) ($suggestion['allDay'] ?? 0),
                'timezone' => $suggestion['timezone'] ?? 'Europe/Berlin',
                'startAt' => $suggestion['startAt'] ?? null,
                'endAt' => $suggestion['endAt'] ?? null,
                'startDate' => $suggestion['startDate'] ?? null,
                'endDate' => $suggestion['endDate'] ?? null,
                'address' => $suggestion['address'] ?? null,
                'latitude' => $suggestion['latitude'] ?? null,
                'longitude' => $suggestion['longitude'] ?? null,
                'OSMID' => $suggestion['OSMID'] ?? null,
                'citySlug' => $suggestion['citySlug'] ?? null,
                'hastickets' => '0',
            ]);

            $this->planEventRepo->setConfirmed($suggestion['id'], $eventId);
        }
    }

    // ──────────────────────────── interne Helfer ────────────────────────────

    /** @return array<string, mixed> */
    private function requirePlanningTrip(string $tripId): array
    {
        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            throw new \RuntimeException('Planning trip not found');
        }
        if (($trip['state'] ?? 'active') !== 'planning') {
            throw new \RuntimeException('Not a planning trip');
        }
        return $trip;
    }

    private function memberRow(string $tripId, string $userId): ?array
    {
        return $this->memberRepo->findByTripAndUser($tripId, $userId);
    }

    private function assertCanView(string $tripId, AuthenticatedUser $user): void
    {
        $member = $this->memberRow($tripId, $user->id);
        if (!$this->policy->canViewPlan($user, $member['status'] ?? null)) {
            throw new \RuntimeException('Not a planning member');
        }
    }

    /** @return array<string, mixed> */
    private function assertActiveMember(string $tripId, AuthenticatedUser $user, bool $requireMembership): array
    {
        $member = $this->memberRow($tripId, $user->id);
        if (!$this->policy->canManageOwn($user, $member['status'] ?? null)) {
            throw new \RuntimeException('Not a planning member');
        }
        if ($requireMembership && $member === null) {
            throw new \RuntimeException('Not a member');
        }
        return $member ?? [];
    }

    private function assertLeader(string $tripId, AuthenticatedUser $user): void
    {
        $member = $this->memberRow($tripId, $user->id);
        if (!$this->policy->canManagePlan($user, $member['role'] ?? null)) {
            throw new \RuntimeException('Not a planning leader');
        }
    }

    private function assertCanManageSuggestion(string $tripId, AuthenticatedUser $user, ?string $proposedBy): void
    {
        $member = $this->memberRow($tripId, $user->id);
        if (!$this->policy->canManageSuggestion($user, $member['role'] ?? null, $proposedBy)) {
            throw new \RuntimeException('Not allowed');
        }
    }

    private function assertNotLastLeader(string $tripId, array $member, bool $deactivating): void
    {
        if (!$deactivating || ($member['role'] ?? null) !== 'leader') {
            return;
        }
        if (!$this->policy->canRemoveLeader($this->memberRepo->countLeaders($tripId) <= 1)) {
            throw new \RuntimeException('Last leader remains');
        }
    }

    /** @param array<string, mixed> $body */
    private function timingOrFail(array $body, bool $defaultAllDay): array
    {
        try {
            return TravelInput::timingFromBody($body, $defaultAllDay);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /** @param array<string, mixed> $body */
    private function priceFromBody(array $body): ?string
    {
        if (!array_key_exists('pricePerPersonPerNight', $body)
            || $body['pricePerPersonPerNight'] === null
            || $body['pricePerPersonPerNight'] === ''
        ) {
            return null;
        }

        if (!is_numeric($body['pricePerPersonPerNight'])) {
            throw new \RuntimeException('Invalid price');
        }

        return number_format((float) $body['pricePerPersonPerNight'], 2, '.', '');
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeSkippedTopics(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $topics = [];
        foreach ($value as $topic) {
            if (is_string($topic) && in_array($topic, TravelPlanningPolicy::TOPICS, true)) {
                $topics[] = $topic;
            }
        }

        return array_values(array_unique($topics));
    }

    /** @return array<string, mixed> */
    private function getDateOption(string $tripId, string $optionId): array
    {
        $option = $this->dateOptionRepo->findByIdAndTrip($optionId, $tripId);
        if ($option === null) {
            throw new \RuntimeException('Date option not found');
        }

        $option = DateTimeValue::normalizeTimingForOutput($option);
        $option['isFinal'] = (int) $option['isFinal'] === 1;
        $option['responses'] = $this->responses($optionId);

        return $option;
    }

    /** @return array<string, mixed> */
    private function getEventSuggestion(string $tripId, string $suggestionId): array
    {
        $suggestion = $this->planEventRepo->findByIdAndTrip($suggestionId, $tripId);
        if ($suggestion === null) {
            throw new \RuntimeException('Event suggestion not found');
        }

        $suggestion = DateTimeValue::normalizeTimingForOutput($suggestion);
        $suggestion['isConfirmed'] = (int) $suggestion['isConfirmed'] === 1;
        $suggestion['interests'] = $this->interests($suggestionId);

        return $suggestion;
    }

    /** @return list<array<string, mixed>> */
    private function dateOptionsWithResponses(string $tripId): array
    {
        $responses = $this->dateResponseRepo->findByTrip($tripId);
        $byOption = [];
        foreach ($responses as $response) {
            $byOption[$response['dateOptionId']][] = [
                'userId' => $response['userId'],
                'availability' => $response['availability'],
            ];
        }

        return array_map(function (array $option) use ($byOption): array {
            $option = DateTimeValue::normalizeTimingForOutput($option);
            $option['isFinal'] = (int) $option['isFinal'] === 1;
            $option['responses'] = $byOption[$option['id']] ?? [];
            return $option;
        }, $this->dateOptionRepo->findByTrip($tripId));
    }

    /** @return list<array<string, mixed>> */
    private function eventSuggestionsWithInterests(string $tripId): array
    {
        $interests = $this->eventInterestRepo->findByTrip($tripId);
        $bySuggestion = [];
        foreach ($interests as $interest) {
            $bySuggestion[$interest['eventSuggestionId']][] = [
                'userId' => $interest['userId'],
                'interest' => $interest['interest'],
            ];
        }

        return array_map(function (array $suggestion) use ($bySuggestion): array {
            $suggestion = DateTimeValue::normalizeTimingForOutput($suggestion);
            $suggestion['isConfirmed'] = (int) $suggestion['isConfirmed'] === 1;
            $suggestion['interests'] = $bySuggestion[$suggestion['id']] ?? [];
            return $suggestion;
        }, $this->planEventRepo->findByTrip($tripId));
    }

    /** @return list<array<string, mixed>> */
    private function responses(string $optionId): array
    {
        return array_map(
            fn(array $r): array => ['userId' => $r['userId'], 'availability' => $r['availability']],
            $this->dateResponseRepo->findByOption($optionId),
        );
    }

    /** @return list<array<string, mixed>> */
    private function interests(string $suggestionId): array
    {
        return array_map(
            fn(array $r): array => ['userId' => $r['userId'], 'interest' => $r['interest']],
            $this->eventInterestRepo->findBySuggestion($suggestionId),
        );
    }

    /** @return array<string, mixed> */
    private function enrichPlanSummary(array $trip, AuthenticatedUser $user): array
    {
        $member = $this->memberRow($trip['id'], $user->id);
        $role = $member['role'] ?? null;

        $trip['state'] = $trip['state'] ?? 'planning';
        $trip['role'] = $role;
        $trip['memberStatus'] = $member['status'] ?? null;
        $trip['canManage'] = $this->policy->canManagePlan($user, $role);
        $trip['memberCount'] = count($this->memberRepo->findActiveByTrip($trip['id']));

        $topicStatus = [];
        foreach ($this->topicRepo->findByTrip($trip['id']) as $topic) {
            $topicStatus[$topic['topic']] = $topic['status'];
        }
        $trip['topicStatus'] = $topicStatus;

        return DateTimeValue::normalizeTimingForOutput($trip);
    }
}
