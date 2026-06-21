<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class ContactInfoRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return array<string, mixed>|null */
    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ContactInfo WHERE userId = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
}
