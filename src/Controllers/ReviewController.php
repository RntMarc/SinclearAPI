<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\ReviewPolicy;
use Sinclear\Api\Services\ReviewService;

final readonly class ReviewController
{
    public function __construct(
        private ReviewService $reviewService,
        private ReviewPolicy $policy,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $placeId = $args['placeId'];
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        try {
            $result = $this->reviewService->listReviews($placeId, $page, $limit);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => 'place_not_found'], 404, $response);
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $placeId = $args['placeId'];
        $body = $request->getParsedBody();

        $rating = isset($body['rating']) ? (int) $body['rating'] : 0;
        $comment = isset($body['comment']) && is_string($body['comment'])
            ? trim($body['comment'])
            : null;

        if ($rating < 1 || $rating > 5) {
            return ResponseFactory::json(['error' => 'invalid_rating'], 400, $response);
        }

        if ($comment !== null && $comment === '') {
            $comment = null;
        }

        try {
            $review = $this->reviewService->createReview($placeId, $user->id, $rating, $comment);
            return ResponseFactory::json(['data' => $review], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => 'place_not_found'], 404, $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $reviewId = $args['reviewId'];
        $body = $request->getParsedBody();

        $review = $this->reviewService->getReview($reviewId);
        if ($review === null) {
            return ResponseFactory::json(['error' => 'review_not_found'], 404, $response);
        }

        if (!$this->policy->canModify($user, $review['userId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $rating = isset($body['rating']) ? (int) $body['rating'] : $review['rating'];
        $comment = array_key_exists('comment', $body)
            ? (is_string($body['comment']) ? trim($body['comment']) : null)
            : $review['comment'];

        if ($rating < 1 || $rating > 5) {
            return ResponseFactory::json(['error' => 'invalid_rating'], 400, $response);
        }

        try {
            $updated = $this->reviewService->updateReview($reviewId, $rating, $comment);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => 'review_not_found'], 404, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $reviewId = $args['reviewId'];

        $review = $this->reviewService->getReview($reviewId);
        if ($review === null) {
            return ResponseFactory::noContent($response);
        }

        if (!$this->policy->canModify($user, $review['userId'])) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->reviewService->deleteReview($reviewId);
        return ResponseFactory::noContent($response);
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
