<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\PhotoRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\UserPolicy;
use Sinclear\Api\Services\UnsplashService;

/**
 * Photo feed backed by the Unsplash API.
 *
 * Only users who linked an Unsplash handle are considered, and only their
 * photos that the requesting user is allowed to see (see UserPolicy and the
 * user's `unsplashVisibility`). Results are always ordered newest first.
 */
final readonly class PhotoController
{
    private const int DEFAULT_LIMIT = 30;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private PhotoRepository $photoRepo,
        private UnsplashService $unsplashService,
        private UserPolicy $userPolicy,
    ) {}

    public function feed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));

        $photos = [];
        foreach ($this->photoRepo->findUsersWithUnsplash() as $candidate) {
            if (!$this->canViewUser($user, $candidate)) {
                continue;
            }
            $fetched = $this->unsplashService->getUserPhotos((string) $candidate['unsplashHandle']);
            if ($fetched === null) {
                continue;
            }
            $photos = array_merge($photos, $this->withAuthor($fetched, $candidate));
        }

        $photos = $this->sortNewestFirst($photos);

        $total = count($photos);
        $offset = ($page - 1) * $limit;
        $pageItems = array_slice($photos, $offset, $limit);

        $meta = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ];

        return ResponseFactory::paginated($pageItems, $meta, $response);
    }

    /** @param array<string, string> $args */
    public function userPhotos(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $userId = (string) $args['id'];

        $target = $this->photoRepo->findUserWithUnsplash($userId);
        if ($target === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if (!$this->canViewUser($user, $target)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $fetched = $this->unsplashService->getUserPhotos((string) $target['unsplashHandle']) ?? [];
        $photos = $this->sortNewestFirst($this->withAuthor($fetched, $target));

        return ResponseFactory::json(['data' => $photos], 200, $response);
    }

    /** @param array<string, mixed> $candidate */
    private function canViewUser(AuthenticatedUser $user, array $candidate): bool
    {
        $visibility = (int) ($candidate['unsplashVisibility'] ?? 1);
        return $this->userPolicy->canView($user, (string) $candidate['id'], $visibility);
    }

    /**
     * @param list<array<string, mixed>> $photos
     * @param array<string, mixed> $author
     * @return list<array<string, mixed>>
     */
    private function withAuthor(array $photos, array $author): array
    {
        $authorInfo = [
            'id' => $author['id'],
            'displayName' => $author['displayName'] ?? null,
            'avatar' => $author['avatar'] ?? null,
        ];

        return array_map(
            static fn(array $photo): array => $photo + ['author' => $authorInfo],
            $photos,
        );
    }

    /**
     * @param list<array<string, mixed>> $photos
     * @return list<array<string, mixed>>
     */
    private function sortNewestFirst(array $photos): array
    {
        usort(
            $photos,
            static fn(array $a, array $b): int =>
                strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')),
        );
        return $photos;
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
