<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;

final readonly class ReviewService
{
    private const int PHOTO_MAX_AGE_SECONDS = 86400;

    public function __construct(
        private DiscoverReviewRepository $reviewRepo,
        private DiscoverPlaceRepository $placeRepo,
        private ImageService $imageService,
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

    public function getReviewPhoto(string $reviewId, string $userId): ?string
    {
        $review = $this->reviewRepo->getPhoto($reviewId);
        if ($review === null) {
            throw new \RuntimeException('review_not_found');
        }

        if ($review['userId'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        return $review['photo'];
    }

    public function setReviewPhoto(string $reviewId, string $userId, string $photo): array
    {
        $review = $this->reviewRepo->getPhoto($reviewId);
        if ($review === null) {
            throw new \RuntimeException('review_not_found');
        }

        if ($review['userId'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        $createdAt = new \DateTimeImmutable($review['createdAt'], new \DateTimeZone('UTC'));
        $deadline = $createdAt->modify('+' . (self::PHOTO_MAX_AGE_SECONDS) . ' seconds');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($now > $deadline) {
            throw new \RuntimeException('photo_deadline_exceeded');
        }

        $this->imageService->validate($photo);

        $this->reviewRepo->setPhoto($reviewId, $photo);

        $full = $this->reviewRepo->findById($reviewId);
        return $this->formatReview($full);
    }

    public function listPlacePhotos(string $placeId, int $page, int $limit): array
    {
        $place = $this->placeRepo->findById($placeId);
        if ($place === null) {
            throw new \RuntimeException('place_not_found');
        }

        $result = $this->reviewRepo->listPhotosByPlace($placeId, $page, $limit);
        $result['data'] = array_map(fn(array $r) => $this->formatPhoto($r), $result['data']);
        return $result;
    }

    private function formatReview(array $review): array
    {
        return [
            'id' => $review['id'],
            'placeId' => $review['placeId'],
            'userId' => $review['userId'],
            'userDisplayName' => $review['userDisplayName'] ?? null,
            'userImage' => $review['userImage'] ?? null,
            'rating' => (int) $review['rating'],
            'comment' => $review['comment'],
            'photo' => $review['photo'] ?? null,
            'createdAt' => $review['createdAt'],
        ];
    }

    private function formatPhoto(array $row): array
    {
        return [
            'id' => $row['id'],
            'photo' => $row['photo'],
            'rating' => (int) $row['rating'],
            'userDisplayName' => $row['userDisplayName'] ?? null,
            'userImage' => $row['userImage'] ?? null,
            'createdAt' => $row['createdAt'],
        ];
    }
}
