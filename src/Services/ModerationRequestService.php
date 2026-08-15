<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;
use Sinclear\Api\Repository\FeedbackCommentRepository;
use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\FeedPostCommentRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\ModerationRequestRepository;
use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Repository\RecipeReviewRepository;
use Sinclear\Api\Repository\StoryRepository;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTicketRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class ModerationRequestService
{
    public const array VALID_REQUEST_TYPES = ['report', 'deletion', 'other'];

    public const array VALID_OBJECT_TYPES = [
        'user', 'forum_post', 'recipe', 'explore_place',
        'recipe_review', 'forum_comment', 'explore_comment',
        'feedback_suggestion', 'feedback_comment',
        'travel_trip', 'travel_event', 'travel_accommodation', 'travel_ticket',
        'subscription', 'calendar_event', 'story',
    ];

    public const array VALID_STATUSES = [
        'unread', 'read', 'in_work', 'external_contact',
        'public_decision', 'accepted', 'denied', 'postponed',
    ];

    public function __construct(
        private ModerationRequestRepository $requestRepo,
        private RecipeRepository $recipeRepo,
        private DiscoverPlaceRepository $placeRepo,
        private FeedPostRepository $postRepo,
        private UserRepository $userRepo,
        private RecipeReviewRepository $recipeReviewRepo,
        private FeedPostCommentRepository $feedPostCommentRepo,
        private DiscoverReviewRepository $discoverReviewRepo,
        private FeedbackSuggestionRepository $feedbackSuggestionRepo,
        private FeedbackCommentRepository $feedbackCommentRepo,
        private TravelEventRepository $travelEventRepo,
        private TravelTicketRepository $travelTicketRepo,
        private TravelRelationRepository $travelRelationRepo,
        private SubscriptionRepository $subscriptionRepo,
        private CalendarEventRepository $calendarEventRepo,
        private StoryRepository $storyRepo,
    ) {}

    public function createRequest(
        string $userId,
        string $requestType,
        string $objectType,
        string $objectId,
        string $message,
    ): array {
        $message = trim($message);
        if ($message === '') {
            throw new \RuntimeException('message_required');
        }

        if (!in_array($requestType, self::VALID_REQUEST_TYPES, true)) {
            throw new \RuntimeException('invalid_request_type');
        }

        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new \RuntimeException('invalid_object_type');
        }

        $ownerId = $this->resolveOwner($objectType, $objectId);
        if ($ownerId === null) {
            throw new \RuntimeException('object_not_found');
        }

        if ($objectType === 'story' && $requestType === 'deletion') {
            throw new \RuntimeException('deletion_not_supported');
        }

        if ($requestType === 'report' && $ownerId === $userId) {
            throw new \RuntimeException('cannot_report_own');
        }

        if ($requestType === 'deletion' && $ownerId !== $userId) {
            throw new \RuntimeException('cannot_request_deletion_foreign');
        }

        $id = $this->requestRepo->create([
            'userId' => $userId,
            'requestType' => $requestType,
            'objectType' => $objectType,
            'objectId' => $objectId,
            'message' => $message,
        ]);

        $request = $this->requestRepo->findById($id);
        return $this->formatRequest($request);
    }

    public function listMine(string $userId, int $page, int $limit): array
    {
        $result = $this->requestRepo->listByUser($userId, $page, $limit);
        $result['data'] = array_map(fn(array $r) => $this->formatRequest($r), $result['data']);
        return $result;
    }

    public function listAll(
        int $page,
        int $limit,
        ?string $status = null,
        ?string $objectType = null,
        ?string $requestType = null,
    ): array {
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            throw new \RuntimeException('invalid_status');
        }
        if ($objectType !== null && !in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new \RuntimeException('invalid_object_type');
        }
        if ($requestType !== null && !in_array($requestType, self::VALID_REQUEST_TYPES, true)) {
            throw new \RuntimeException('invalid_request_type');
        }

        $result = $this->requestRepo->list($page, $limit, $status, $objectType, $requestType);
        $result['data'] = array_map(fn(array $r) => $this->formatRequest($r), $result['data']);
        return $result;
    }

    public function getById(string $id): ?array
    {
        $request = $this->requestRepo->findById($id);
        if ($request === null) {
            return null;
        }
        return $this->formatRequest($request);
    }

    public function updateRequest(string $id, string $status, ?string $adminComment): array
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \RuntimeException('invalid_status');
        }

        $request = $this->requestRepo->findById($id);
        if ($request === null) {
            throw new \RuntimeException('request_not_found');
        }

        $adminComment = $adminComment !== null ? trim($adminComment) : null;
        if ($adminComment === '') {
            $adminComment = null;
        }

        $this->requestRepo->update($id, $status, $adminComment);
        $updated = $this->requestRepo->findById($id);

        return $this->formatRequest($updated);
    }

    public function getStatusCounts(): array
    {
        return $this->requestRepo->getStatusCounts();
    }

private function resolveOwner(string $objectType, string $objectId): ?string
    {
        return match ($objectType) {
            'recipe' => $this->safeGet($this->recipeRepo->findById($objectId), 'creatorId'),
            'explore_place' => $this->safeGet($this->placeRepo->findById($objectId), 'creatorId'),
            'forum_post' => $this->safeGet($this->postRepo->findById($objectId), 'userId'),
            'user' => $this->userRepo->findById($objectId) !== null ? $objectId : null,

            'recipe_review' => $this->safeGet($this->recipeReviewRepo->findById($objectId), 'userId'),
            'forum_comment' => $this->safeGet($this->feedPostCommentRepo->findById($objectId), 'userId'),
            'explore_comment' => $this->safeGet($this->discoverReviewRepo->findById($objectId), 'userId'),
            'feedback_suggestion' => $this->safeGet($this->feedbackSuggestionRepo->findById($objectId), 'userId'),
            'feedback_comment' => $this->safeGet($this->feedbackCommentRepo->findById($objectId), 'userId'),
            'calendar_event' => $this->safeGet($this->calendarEventRepo->findById($objectId), 'creatorId'),

            'travel_ticket' => $this->safeGet($this->travelTicketRepo->findById($objectId), 'user'),

            'travel_trip' => $this->resolveFirstParticipant(
                $this->travelRelationRepo->findParticipantsByTrip($objectId),
            ),
            'travel_event' => $this->resolveFirstParticipant(
                $this->travelEventRepo->findParticipantsByEvent($objectId),
            ),
            'travel_accommodation' => $this->resolveFirstParticipant(
                $this->travelRelationRepo->findUsersByAccommodation($objectId),
            ),

            'subscription' => $this->resolveFirstParticipant(
                $this->subscriptionRepo->findParticipants($objectId),
            ),

            'story' => $this->safeGet($this->storyRepo->findById($objectId), 'userId'),

            default => null,
        };
    }

    private function safeGet(?array $row, string $key): ?string
    {
        return $row[$key] ?? null;
    }

    private function resolveFirstParticipant(array $participants): ?string
    {
        if ($participants === []) {
            return null;
        }
        return $participants[0]['id'] ?? $participants[0]['userId'] ?? null;
    }

    private function formatRequest(array $r): array
    {
        return [
            'id' => $r['id'],
            'userId' => $r['userId'],
            'userDisplayName' => $r['userDisplayName'] ?? null,
            'userImage' => $r['userImage'] ?? null,
            'requestType' => $r['requestType'],
            'objectType' => $r['objectType'],
            'objectId' => $r['objectId'],
            'message' => $r['message'],
            'status' => $r['status'],
            'adminComment' => $r['adminComment'],
            'createdAt' => $r['createdAt'],
            'updatedAt' => $r['updatedAt'],
        ];
    }
}
