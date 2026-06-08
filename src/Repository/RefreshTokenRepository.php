<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for hashed refresh tokens.
 */
final class RefreshTokenRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'refresh_tokens';
    }

    protected function columns(): array
    {
        return ['id', 'family_id', 'user_id', 'token_hash', 'expires_at', 'revoked_at', 'created_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function store(string $familyId, string $userId, string $tokenHash, string $expiresAt): array
    {
        return $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'family_id' => $familyId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `refresh_tokens` WHERE `token_hash` = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function revoke(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `refresh_tokens` SET `revoked_at` = NOW() WHERE `id` = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function revokeAllForFamily(string $familyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `refresh_tokens` SET `revoked_at` = NOW() WHERE `family_id` = :familyId AND `revoked_at` IS NULL'
        );
        $stmt->execute(['familyId' => $familyId]);
    }
}
