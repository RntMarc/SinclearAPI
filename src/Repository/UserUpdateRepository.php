<?php

namespace Sinclear\Api\Repository;

use PDO;
use Psr\Log\LoggerInterface;

final readonly class UserUpdateRepository
{
    public function __construct(
        private PDO $pdo,
        private LoggerInterface $logger,
    ) {}

    public function updateDisplayName(string $userId, string $displayName): void
    {
        $this->logger->debug('UserUpdateRepository: updateDisplayName', [
            'userId' => $userId,
        ]);
        $stmt = $this->pdo->prepare('UPDATE User SET displayName = ? WHERE id = ?');
        $stmt->execute([$displayName, $userId]);
    }

    public function updateBirthday(string $userId, ?string $birthday): void
    {
        $this->logger->debug('UserUpdateRepository: updateBirthday', [
            'userId' => $userId,
        ]);
        $stmt = $this->pdo->prepare('UPDATE User SET birthday = ? WHERE id = ?');
        $stmt->execute([$birthday, $userId]);
    }

    public function updateEmail(string $userId, string $email): void
    {
        $this->logger->debug('UserUpdateRepository: updateEmail', [
            'userId' => $userId,
        ]);
        $stmt = $this->pdo->prepare('UPDATE User SET email = ? WHERE id = ?');
        $stmt->execute([$email, $userId]);
    }

    public function updateDiscordId(string $userId, ?string $discordId): void
    {
        $this->logger->debug('UserUpdateRepository: updateDiscordId', [
            'userId' => $userId,
        ]);
        $stmt = $this->pdo->prepare('UPDATE User SET discordId = ? WHERE id = ?');
        $stmt->execute([$discordId, $userId]);
    }

    public function updateImage(string $userId, ?string $image): void
    {
        $this->logger->debug('UserUpdateRepository: updateImage', [
            'userId' => $userId,
            'image_is_null' => $image === null,
            'image_length' => $image !== null ? strlen($image) : 0,
            'image_preview' => $image !== null ? substr($image, 0, 80) . '...' : 'null',
        ]);
        $stmt = $this->pdo->prepare('UPDATE User SET image = ? WHERE id = ?');
        $stmt->execute([$image, $userId]);
        $this->logger->debug('UserUpdateRepository: updateImage executed successfully', [
            'rowCount' => $stmt->rowCount(),
        ]);
    }

    /** @param string|int $value */
    public function updateField(string $userId, string $field, mixed $value): void
    {
        $this->logger->debug('UserUpdateRepository: updateField', [
            'userId' => $userId,
            'field' => $field,
        ]);
        $allowed = ['displayName', 'birthday', 'email', 'discordId', 'emailVisibility', 'birthdayVisibility', 'image', 'onboardingCompleted'];
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid field: $field");
        }
        $stmt = $this->pdo->prepare("UPDATE User SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $userId]);
        $this->logger->debug('UserUpdateRepository: updateField executed', [
            'rowCount' => $stmt->rowCount(),
        ]);
    }
}
