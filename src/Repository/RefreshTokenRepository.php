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
        return 'RefreshToken';
    }

    protected function columns(): array
    {
        return ['id', 'familyId', 'userId', 'tokenHash', 'expiresAt', 'revokedAt', 'createdAt'];
    }

    /**
     * @return array<string, mixed>
     */
    public function store(string $familyId, string $userId, string $tokenHash, string $expiresAt): array
    {
        return $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'familyId' => $familyId,
            'userId' => $userId,
            'tokenHash' => $tokenHash,
            'expiresAt' => $expiresAt,
            'revokedAt' => null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `RefreshToken` WHERE `tokenHash` = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function revoke(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `RefreshToken` SET `revokedAt` = NOW() WHERE `id` = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function revokeAllForFamily(string $familyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `RefreshToken` SET `revokedAt` = NOW() WHERE `familyId` = :familyId AND `revokedAt` IS NULL'
        );
        $stmt->execute(['familyId' => $familyId]);
    }
}
