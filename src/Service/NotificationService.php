<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Notification badge counts and listing helpers.
 */
final class NotificationService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function badges(AuthenticatedUser $user): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `type`, COUNT(*) as cnt FROM `Notification` WHERE userId = :userId GROUP BY `type`'
        );
        $stmt->execute(['userId' => $user->id]);
        $rows = $stmt->fetchAll();

        $badges = ['total' => 0];
        foreach ($rows as $row) {
            $badges[$row['type']] = (int) $row['cnt'];
            $badges['total'] += (int) $row['cnt'];
        }
        return $badges;
    }

    /**
     * @param list<string> $types
     */
    public function readByType(AuthenticatedUser $user, array $types): void
    {
        if ($types === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = sprintf(
            'DELETE FROM `Notification` WHERE userId = ? AND `type` IN (%s)',
            $placeholders
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$user->id], $types));
    }
}
