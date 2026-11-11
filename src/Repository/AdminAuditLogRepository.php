<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class AdminAuditLogRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function create(
        string $adminId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO AdminAuditLog (id, adminId, action, entityType, entityId, ipAddress, userAgent, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([
            $id,
            $adminId,
            $action,
            $entityType,
            $entityId,
            $ipAddress !== null && $ipAddress !== '' ? $ipAddress : null,
            $userAgent !== null && $userAgent !== '' ? $userAgent : null,
        ]);
    }
}
