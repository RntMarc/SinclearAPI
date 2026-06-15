<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class DiscoverReviewRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function hasReviews(string $placeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM DiscoverReview WHERE placeId = ?');
        $stmt->execute([$placeId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
