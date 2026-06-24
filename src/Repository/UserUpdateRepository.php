<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class UserUpdateRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function updateDisplayName(string $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare('UPDATE User SET displayName = ? WHERE id = ?');
        $stmt->execute([$displayName, $userId]);
    }

    public function updateBirthday(string $userId, ?string $birthday): void
    {
        $stmt = $this->pdo->prepare('UPDATE User SET birthday = ? WHERE id = ?');
        $stmt->execute([$birthday, $userId]);
    }

    public function updateEmail(string $userId, string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE User SET email = ? WHERE id = ?');
        $stmt->execute([$email, $userId]);
    }

    public function updateDiscordId(string $userId, ?string $discordId): void
    {
        $stmt = $this->pdo->prepare('UPDATE User SET discordId = ? WHERE id = ?');
        $stmt->execute([$discordId, $userId]);
    }

    public function updateImage(string $userId, ?string $image): void
    {
        $stmt = $this->pdo->prepare('UPDATE User SET image = ? WHERE id = ?');
        $stmt->execute([$image, $userId]);
    }

    /** @param string|int $value */
    public function updateField(string $userId, string $field, mixed $value): void
    {
        $allowed = ['displayName', 'birthday', 'email', 'discordId', 'emailVisibility', 'birthdayVisibility', 'image'];
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid field: $field");
        }
        $stmt = $this->pdo->prepare("UPDATE User SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $userId]);
    }
}
