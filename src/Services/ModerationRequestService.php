<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\ModerationRequestRepository;
use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class ModerationRequestService
{
    public const array VALID_REQUEST_TYPES = ['report', 'deletion', 'other'];

    public const array VALID_OBJECT_TYPES = ['user', 'forum_post', 'recipe', 'explore_place'];

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
            'recipe' => $this->recipeRepo->findById($objectId)['creatorId'] ?? null,
            'explore_place' => $this->placeRepo->findById($objectId)['creatorId'] ?? null,
            'forum_post' => $this->postRepo->findById($objectId)['userId'] ?? null,
            'user' => $this->userRepo->findById($objectId) !== null ? $objectId : null,
            default => null,
        };
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
