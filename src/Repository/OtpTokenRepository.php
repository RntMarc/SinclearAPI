<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for OTP tokens.
 */
final class OtpTokenRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'OtpToken';
    }

    protected function columns(): array
    {
        return ['id', 'email', 'code', 'expiresAt', 'usedAt', 'createdAt'];
    }

    public function invalidateUnusedForEmail(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `OtpToken` WHERE `email` = :email AND `usedAt` IS NULL'
        );
        $stmt->execute(['email' => $email]);
    }

    public function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `OtpToken` WHERE `expiresAt` < NOW()');
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValid(string $email, string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `OtpToken` WHERE `email` = :email AND `code` = :code
             AND `expiresAt` > NOW() AND `usedAt` IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markUsed(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE `OtpToken` SET `usedAt` = NOW() WHERE `id` = :id');
        $stmt->execute(['id' => $id]);
    }
}
