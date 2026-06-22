<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\ExplorePolicy;
use Sinclear\Api\Services\ExploreService;
use Sinclear\Api\Repository\DiscoverReviewRepository;

final readonly class ExploreController
{
    public function __construct(
        private ExploreService $exploreService,
        private ExplorePolicy $policy,
        private DiscoverReviewRepository $reviewRepo,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $category = !empty($params['category']) ? $params['category'] : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $sort = !empty($params['sort']) ? $params['sort'] : null;
        $cuisine = !empty($params['cuisine']) ? $params['cuisine'] : null;

        $validSorts = ['name_asc', 'name_desc', 'created_asc', 'created_desc', 'rating_asc', 'rating_desc'];
        if ($sort !== null && !in_array($sort, $validSorts, true)) {
            return ResponseFactory::json(['error' => 'invalid_sort'], 400, $response);
        }

        if ($category !== null && !in_array($category, ['gastronomy', 'leisure'], true)) {
            return ResponseFactory::json(['error' => 'invalid_category'], 400, $response);
        }

        $result = $this->exploreService->listPlaces($category, $page, $limit, $sort, $cuisine);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function random(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $category = !empty($params['category']) ? $params['category'] : null;

        if ($category !== null && !in_array($category, ['gastronomy', 'leisure'], true)) {
            return ResponseFactory::json(['error' => 'invalid_category'], 400, $response);
        }

        $places = $this->exploreService->randomPlaces($limit, $category);
        return ResponseFactory::json(['data' => $places], 200, $response);
    }

    public function getBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $bookmarked = $this->exploreService->getBookmarkStatus($user->id, $args['id']);
        return ResponseFactory::json(['data' => ['bookmarked' => $bookmarked]], 200, $response);
    }

    public function setBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        try {
            $result = $this->exploreService->setBookmark($user->id, $args['id']);
            return ResponseFactory::json(['data' => $result], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Place already bookmarked' ? 409 : 400;
            return ResponseFactory::json(['error' => 'bookmark_exists'], $code, $response);
        }
    }

    public function removeBookmark(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $this->exploreService->removeBookmark($user->id, $args['id']);
        return ResponseFactory::noContent($response);
    }

    public function listBookmarks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->exploreService->listBookmarks($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $place = $this->exploreService->getPlace($args['id']);
        if ($place === null) {
            return ResponseFactory::json(['error' => 'place_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $place], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $osmId = (int) ($body['osmId'] ?? 0);
        $osmType = strtoupper(trim($body['osmType'] ?? ''));

        if ($osmId <= 0) {
            return ResponseFactory::json(['error' => 'invalid_osm_id'], 400, $response);
        }

        if (!in_array($osmType, ['N', 'W', 'R'], true)) {
            return ResponseFactory::json(['error' => 'invalid_osm_type'], 400, $response);
        }

        try {
            $place = $this->exploreService->createPlace($osmId, $osmType, $user->id);
            return ResponseFactory::json(['data' => $place], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Place already exists' ? 409 : 400;
            return ResponseFactory::json(['error' => $code === 409 ? 'place_exists' : 'creation_failed'], $code, $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $place = $this->exploreService->refreshPlace($args['id']);
            return ResponseFactory::json(['data' => $place], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Place not found' ? 404 : 400;
            return ResponseFactory::json(['error' => $code === 404 ? 'place_not_found' : 'refresh_failed'], $code, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $place = $this->exploreService->getPlace($args['id']);
        if ($place === null) {
            return ResponseFactory::json(['error' => 'place_not_found'], 404, $response);
        }

        $hasReviews = $this->reviewRepo->hasReviews($args['id']);

        if (!$this->policy->canDelete($user, $place['creatorId'], $hasReviews)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->exploreService->deletePlace($args['id']);

        return ResponseFactory::noContent($response);
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        if (!empty($params['category']) && !in_array($params['category'], ['gastronomy', 'leisure'], true)) {
            return ResponseFactory::json(['error' => 'invalid_category'], 400, $response);
        }

        $sort = !empty($params['sort']) ? $params['sort'] : null;
        $validSorts = ['name_asc', 'name_desc', 'created_asc', 'created_desc', 'rating_asc', 'rating_desc'];
        if ($sort !== null && !in_array($sort, $validSorts, true)) {
            return ResponseFactory::json(['error' => 'invalid_sort'], 400, $response);
        }
        $params['sort'] = $sort;

        $params['page'] = max(1, (int) ($params['page'] ?? 1));
        $params['limit'] = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $params['radius'] = isset($params['radius']) ? min(50000, max(1, (int) $params['radius'])) : null;

        if (isset($params['lat'])) {
            $params['lat'] = (float) $params['lat'];
        }
        if (isset($params['lon'])) {
            $params['lon'] = (float) $params['lon'];
        }

        $result = $this->exploreService->searchPlaces($params);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
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
