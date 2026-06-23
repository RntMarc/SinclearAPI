<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;

final readonly class ReviewService
{
    public function __construct(
        private DiscoverReviewRepository $reviewRepo,
        private DiscoverPlaceRepository $placeRepo,
    ) {}

    public function listReviews(string $placeId, int $page, int $limit): array
    {
        $place = $this->placeRepo->findById($placeId);
        if ($place === null) {
            throw new \RuntimeException('Place not found');
        }

        $result = $this->reviewRepo->listByPlace($placeId, $page, $limit);
        $result['data'] = array_map(fn(array $r) => $this->formatReview($r), $result['data']);
        return $result;
    }

    public function createReview(string $placeId, string $userId, int $rating, ?string $comment): array
    {
        $place = $this->placeRepo->findById($placeId);
        if ($place === null) {
            throw new \RuntimeException('Place not found');
        }

        $id = $this->reviewRepo->create([
            'placeId' => $placeId,
            'userId' => $userId,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $review = $this->reviewRepo->findById($id);
        return $this->formatReview($review);
    }

    public function getReview(string $id): ?array
    {
        $review = $this->reviewRepo->findById($id);
        if ($review === null) {
            return null;
        }
        return $this->formatReview($review);
    }

    public function updateReview(string $reviewId, int $rating, ?string $comment): array
    {
        $review = $this->reviewRepo->findById($reviewId);
        if ($review === null) {
            throw new \RuntimeException('Review not found');
        }

        $this->reviewRepo->update($reviewId, [
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $review = $this->reviewRepo->findById($reviewId);
        return $this->formatReview($review);
    }

    public function deleteReview(string $reviewId): void
    {
        $this->reviewRepo->delete($reviewId);
    }

    private function formatReview(array $review): array
    {
        return [
            'id' => $review['id'],
            'placeId' => $review['placeId'],
            'userId' => $review['userId'],
            'rating' => (int) $review['rating'],
            'comment' => $review['comment'],
            'createdAt' => $review['createdAt'],
        ];
    }
}
