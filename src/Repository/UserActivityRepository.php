<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class UserActivityRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Update the last active timestamp for a user.
     *
     * Uses INSERT IGNORE + UPDATE to avoid race conditions.
     * The ON UPDATE clause in the schema handles subsequent updates.
     */
    public function updateActivity(string $userId, string $endpoint): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO UserActivity (userId, lastActiveAt, lastActiveEndpoint)
             VALUES (?, NOW(3), ?)
             ON DUPLICATE KEY UPDATE
               lastActiveAt = NOW(3),
               lastActiveEndpoint = VALUES(lastActiveEndpoint)'
        );
        $stmt->execute([$userId, $endpoint]);
    }

    /**
     * Get the last active timestamp for a single user.
     *
     * @return array{lastActiveAt: string, lastActiveEndpoint: ?string}|null
     */
    public function getLastActive(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lastActiveAt, lastActiveEndpoint
             FROM UserActivity
             WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get last active timestamps for multiple users in bulk.
     *
     * @param list<string> $userIds
     * @return array<string, array{lastActiveAt: string, lastActiveEndpoint: ?string}>
     */
    public function getBulkLastActive(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT userId, lastActiveAt, lastActiveEndpoint
             FROM UserActivity
             WHERE userId IN ({$placeholders})"
        );
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[$row['userId']] = [
                'lastActiveAt' => $row['lastActiveAt'],
                'lastActiveEndpoint' => $row['lastActiveEndpoint'],
            ];
        }
        return $results;
    }
}
