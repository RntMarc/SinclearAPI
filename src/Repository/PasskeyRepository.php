<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for WebAuthn passkeys.
 */
final class PasskeyRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'Passkey';
    }

    protected function columns(): array
    {
        return [
            'id', 'userId', 'credentialId', 'publicKey', 'counter',
            'transports', 'name', 'createdAt', 'lastUsedAt',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `Passkey` WHERE `userId` = :userId');
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `Passkey` WHERE `credentialId` = :credentialId LIMIT 1'
        );
        $stmt->execute(['credentialId' => $credentialId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateCounter(string $id, int $counter): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `Passkey` SET `counter` = :counter, `lastUsedAt` = NOW() WHERE `id` = :id'
        );
        $stmt->execute(['counter' => $counter, 'id' => $id]);
    }
}
