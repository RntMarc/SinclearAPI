<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for the User table.
 */
final class UserRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'User';
    }

    protected function columns(): array
    {
        return [
            'id', 'email', 'passwordHash', 'displayName', 'createdAt',
            'birthday', 'birthdayVisibility', 'isAdmin', 'discordId',
            'image', 'emailVisibility', 'onboardingCompleted',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `User` WHERE `email` = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDiscordId(string $discordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `User` WHERE `discordId` = :discordId LIMIT 1');
        $stmt->execute(['discordId' => $discordId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
