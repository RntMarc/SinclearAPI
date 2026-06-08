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
        return 'jti_blacklist';
    }

    protected function columns(): array
    {
        return ['id', 'jti', 'expires_at', 'created_at'];
    }

    public function add(string $jti, string $expiresAt): void
    {
        $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'jti' => $jti,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function isBlacklisted(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `jti_blacklist` WHERE `jti` = :jti AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['jti' => $jti]);
        return $stmt->fetchColumn() !== false;
    }

    public function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `jti_blacklist` WHERE `expires_at` < NOW()');
        $stmt->execute();
    }
}
