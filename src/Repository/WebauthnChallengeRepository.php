<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for temporary WebAuthn challenges.
 */
final class WebauthnChallengeRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'WebauthnChallenge';
    }

    protected function columns(): array
    {
        return ['id', 'challenge', 'userId', 'expiresAt', 'createdAt'];
    }

    public function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `WebauthnChallenge` WHERE `expiresAt` < NOW()');
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValidChallenge(string $challenge): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `WebauthnChallenge` WHERE `challenge` = :challenge
             AND `expiresAt` > NOW() LIMIT 1'
        );
        $stmt->execute(['challenge' => $challenge]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function deleteByChallenge(string $challenge): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `WebauthnChallenge` WHERE `challenge` = :challenge');
        $stmt->execute(['challenge' => $challenge]);
    }
}
