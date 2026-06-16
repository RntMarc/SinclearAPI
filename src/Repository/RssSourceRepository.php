<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class RssSourceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM RssSource ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
