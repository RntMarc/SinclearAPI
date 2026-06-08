<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for blacklisted JWT JTIs.
 */
final class JtiBlacklistRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'JtiBlacklist';
    }

    protected function columns(): array
    {
        return ['id', 'jti', 'expiresAt', 'createdAt'];
    }

    public function add(string $jti, string $expiresAt): void
    {
        $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'jti' => $jti,
            'expiresAt' => $expiresAt,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    public function isBlacklisted(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `JtiBlacklist` WHERE `jti` = :jti AND `expiresAt` > NOW() LIMIT 1'
        );
        $stmt->execute(['jti' => $jti]);
        return $stmt->fetchColumn() !== false;
    }

    public function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `JtiBlacklist` WHERE `expiresAt` < NOW()');
        $stmt->execute();
    }
}
