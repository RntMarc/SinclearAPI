<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Sinclear\Api\Dto\UserDto;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * GDPR data export for a user.
 */
final class UserExportService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(AuthenticatedUser $user, string $targetUserId): array
    {
        if ($targetUserId !== $user->id && !$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $userStmt = $this->pdo->prepare('SELECT * FROM `User` WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $targetUserId]);
        $userData = $userStmt->fetch();
        if ($userData === false) {
            throw HttpException::notFound();
        }

        $tables = [
            'UserPreferences' => 'userId',
            'ContactInfo' => 'userId',
            'SocialInfo' => 'userId',
            'CloseFriend' => 'userId',
            'Notification' => 'userId',
            'PushSubscription' => 'userId',
        ];

        $export = ['user' => UserDto::fromRow($userData), 'related' => []];

        foreach ($tables as $table => $column) {
            $stmt = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = :userId");
            $stmt->execute(['userId' => $targetUserId]);
            $export['related'][$table] = $stmt->fetchAll();
        }

        return $export;
    }
}
