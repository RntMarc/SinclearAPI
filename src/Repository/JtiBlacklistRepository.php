<?php

namespace Sinclear\Api\Repository;

use DateTimeImmutable;
use PDO;

final readonly class JtiBlacklistRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function isBlacklisted(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM JtiBlacklist WHERE jti = ? AND expiresAt > NOW()'
        );
        $stmt->execute([$jti]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function add(string $jti, DateTimeImmutable $expiresAt): void
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO JtiBlacklist (id, jti, expiresAt, createdAt) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $jti, $expiresAt->format('Y-m-d H:i:s')]);
    }
}
