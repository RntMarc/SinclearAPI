<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class ChatPresenceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Set user as active for the next 5 seconds (push suppression).
     */
    public function touchActiveUntil(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ChatPresence (userId, activeUntil)
             VALUES (?, DATE_ADD(NOW(3), INTERVAL 5 SECOND))
             ON DUPLICATE KEY UPDATE activeUntil = DATE_ADD(NOW(3), INTERVAL 5 SECOND)'
        );
        $stmt->execute([$userId]);
    }

    /**
     * Check if a user is currently active (polling).
     */
    public function isActive(string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ChatPresence WHERE userId = ? AND activeUntil > NOW(3)'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get activeUntil for a user (null if not present).
     */
    public function getActiveUntil(string $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT activeUntil FROM ChatPresence WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Cleanup expired presence entries.
     */
    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ChatPresence WHERE activeUntil < NOW(3)'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
