<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\StoryRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\StoryPolicy;
use Sinclear\Api\Services\ImageService;
use Sinclear\Api\Services\NotificationService;

final readonly class StoryController
{
    private const int MAX_IMAGE_SIZE = 1024 * 1024;
    private const int MAX_IMAGE_DIMENSION = 2000;
    private const int MAX_CAPTION_LENGTH = 1000;

    private const array ERROR_MAP = [
        'invalid_image' => ['error' => 'invalid_image', 'status' => 400],
        'invalid_image_encoding' => ['error' => 'invalid_image_encoding', 'status' => 400],
        'image_too_large' => ['error' => 'image_too_large', 'status' => 400],
        'invalid_image_format' => ['error' => 'invalid_image_format', 'status' => 400],
        'unsupported_image_format' => ['error' => 'unsupported_image_format', 'status' => 400],
        'image_dimensions_too_large' => ['error' => 'image_dimensions_too_large', 'status' => 400],
        'invalid_caption' => ['error' => 'invalid_caption', 'status' => 400],
        'story_not_found' => ['error' => 'story_not_found', 'status' => 404],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
    ];

    public function __construct(
        private StoryRepository $storyRepo,
        private StoryPolicy $policy,
        private ImageService $imageService,
        private UserRepository $userRepo,
        private NotificationService $notificationService,
    ) {}

    public function feed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $stories = $this->storyRepo->findActive();
        $viewedIds = $this->storyRepo->findViewedStoryIds(
            $user->id,
            array_column($stories, 'id'),
        );
        $viewedSet = array_fill_keys($viewedIds, true);

        $groups = [];
        foreach ($stories as $story) {
            $userId = $story['userId'];
            if (!isset($groups[$userId])) {
                $groups[$userId] = [
                    'userId' => $userId,
                    'displayName' => $story['displayName'] ?? null,
                    'avatar' => $story['userImage'] ?? null,
                    'stories' => [],
                ];
            }

            $groups[$userId]['stories'][] = [
                'id' => $story['id'],
                'image' => $story['image'],
                'caption' => $story['caption'],
                'createdAt' => self::stripFractionalSeconds($story['createdAt']),
                'expiresAt' => self::stripFractionalSeconds($story['expiresAt']),
                'viewed' => isset($viewedSet[$story['id']]),
            ];
        }

        return ResponseFactory::json(['data' => array_values($groups)], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (empty($body['image']) || !is_string($body['image'])) {
            return ResponseFactory::json(['error' => 'invalid_image'], 400, $response);
        }

        $caption = null;
        if (isset($body['caption']) && $body['caption'] !== '') {
            if (!is_string($body['caption'])) {
                return ResponseFactory::json(['error' => 'invalid_caption'], 400, $response);
            }
            $caption = trim($body['caption']);
            if ($caption === '') {
                $caption = null;
            } elseif (strlen($caption) > self::MAX_CAPTION_LENGTH) {
                return ResponseFactory::json(['error' => 'invalid_caption'], 400, $response);
            }
        }

        try {
            $image = $this->imageService->validate(
                $body['image'],
                self::MAX_IMAGE_SIZE,
                self::MAX_IMAGE_DIMENSION,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        $id = $this->storyRepo->create([
            'userId' => $user->id,
            'image' => $image,
            'caption' => $caption,
        ]);

        $story = $this->storyRepo->findById($id);
        if ($story === null) {
            return ResponseFactory::json(['error' => 'story_not_found'], 404, $response);
        }

        $response = ResponseFactory::json(['data' => $this->formatStory($story, $user)], 201, $response);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $this->notifyOnStoryCreated($user->id, $id);
        } catch (\Throwable) {
        }

        return $response;
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $story = $this->storyRepo->findById($args['id']);
        if ($story === null) {
            return ResponseFactory::json(['error' => 'story_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $this->formatStory($story, $user)], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $story = $this->storyRepo->findById($args['id']);
        if ($story === null) {
            return ResponseFactory::json(['error' => 'story_not_found'], 404, $response);
        }

        if (!$this->policy->canDelete($user, $story['userId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->storyRepo->delete($story['id']);
        return ResponseFactory::noContent($response);
    }

    public function markViewed(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $story = $this->storyRepo->findById($args['id']);
        if ($story === null) {
            return ResponseFactory::json(['error' => 'story_not_found'], 404, $response);
        }

        $this->storyRepo->markViewed($story['id'], $user->id);

        return ResponseFactory::json(['data' => ['viewed' => true]], 200, $response);
    }

    private function notifyOnStoryCreated(string $authorId, string $storyId): void
    {
        $recipientIds = array_filter(
            $this->userRepo->findAllIds(),
            fn(string $id): bool => $id !== $authorId,
        );

        foreach ($recipientIds as $recipientId) {
            $this->notificationService->create(
                userId: $recipientId,
                type: 'story_post',
                title: '',
                body: '',
                data: [
                    ['relation' => 'story_author', 'object' => 'User', 'identifier' => $authorId],
                    ['relation' => 'story', 'object' => 'Story', 'identifier' => $storyId],
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $story Raw story row incl. userDisplayName/userImage
     */
    private function formatStory(array $story, AuthenticatedUser $user): array
    {
        $viewedIds = $this->storyRepo->findViewedStoryIds($user->id, [$story['id']]);

        return [
            'id' => $story['id'],
            'userId' => $story['userId'],
            'user' => [
                'id' => $story['userId'],
                'displayName' => $story['userDisplayName'] ?? null,
                'avatar' => $story['userImage'] ?? null,
            ],
            'image' => $story['image'],
            'caption' => $story['caption'],
            'createdAt' => self::stripFractionalSeconds($story['createdAt']),
            'expiresAt' => self::stripFractionalSeconds($story['expiresAt']),
            'viewed' => $viewedIds !== [],
            'viewCount' => $this->storyRepo->countViews($story['id']),
        ];
    }

    /**
     * Strip fractional seconds from datetime(3) values.
     * MySQL datetime(3) returns "YYYY-MM-DD HH:MM:SS.fff"; the API
     * contracts require "YYYY-MM-DD HH:MM:SS" (no milliseconds).
     */
    private static function stripFractionalSeconds(string $datetime): string
    {
        $dot = strpos($datetime, '.');
        return $dot !== false ? substr($datetime, 0, $dot) : $datetime;
    }

    private function errorResponse(string $message, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$message] ?? null;
        if ($mapped !== null) {
            return ResponseFactory::json(['error' => $mapped['error']], $mapped['status'], $response);
        }
        return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
