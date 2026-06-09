<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for UserPreferences table.
 */
final class UserPreferencesRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'UserPreferences';
    }

    protected function columns(): array
    {
        return ['id', 'userId', 'theme', 'language', 'primaryColor', 'timezone', 'updatedAt', 'createdAt'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `UserPreferences` WHERE `userId` = :userId LIMIT 1');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
