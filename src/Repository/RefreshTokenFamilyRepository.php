<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for refresh token families (replay detection).
 */
final class RefreshTokenFamilyRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'RefreshTokenFamily';
    }

    protected function columns(): array
    {
        return ['id', 'userId', 'createdAt', 'revokedAt'];
    }

    /**
     * @return array<string, mixed>
     */
    public function createForUser(string $userId): array
    {
        return $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'userId' => $userId,
            'createdAt' => date('Y-m-d H:i:s'),
            'revokedAt' => null,
        ]);
    }

    public function revoke(string $familyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `RefreshTokenFamily` SET `revokedAt` = NOW() WHERE `id` = :id'
        );
        $stmt->execute(['id' => $familyId]);
    }
}
