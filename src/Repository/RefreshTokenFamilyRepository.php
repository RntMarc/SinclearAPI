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
        return 'refresh_token_families';
    }

    protected function columns(): array
    {
        return ['id', 'user_id', 'created_at', 'revoked_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function createForUser(string $userId): array
    {
        return $this->create([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'revoked_at' => null,
        ]);
    }

    public function revoke(string $familyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `refresh_token_families` SET `revoked_at` = NOW() WHERE `id` = :id'
        );
        $stmt->execute(['id' => $familyId]);
    }
}
