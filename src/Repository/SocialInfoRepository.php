<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class SocialInfoRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return array<string, mixed>|null */
    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM SocialInfo WHERE userId = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
}
