<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class EventRelationRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findByEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id AS relationId, r.userId, r.eventId, r.createdAt, r.role,
                    u.email, u.displayName, u.image
             FROM EventRelation r
             JOIN User u ON u.id = r.userId
             WHERE r.eventId = ?
             ORDER BY u.displayName ASC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRole(string $eventId, string $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM EventRelation WHERE eventId = ? AND userId = ? LIMIT 1'
        );
        $stmt->execute([$eventId, $userId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string) $role;
    }

    public function isLeader(string $eventId, string $userId): bool
    {
        return $this->getRole($eventId, $userId) === 'leader';
    }

    public function countLeaders(string $eventId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM EventRelation WHERE eventId = ? AND role = 'leader'"
        );
        $stmt->execute([$eventId]);
        return (int) $stmt->fetchColumn();
    }

    public function addParticipant(string $eventId, string $userId, string $role = 'participant'): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO EventRelation (id, eventId, userId, createdAt, role)
             VALUES (?, ?, ?, NOW(3), ?)'
        );
        $stmt->execute([$id, $eventId, $userId, self::normalizeRole($role)]);
        return $id;
    }

    public function updateRole(string $eventId, string $userId, string $role): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EventRelation SET role = ? WHERE eventId = ? AND userId = ?'
        );
        $stmt->execute([self::normalizeRole($role), $eventId, $userId]);
    }

    public function resetRoles(string $eventId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE EventRelation SET role = 'participant' WHERE eventId = ?"
        );
        $stmt->execute([$eventId]);
    }

    public function removeByEventAndUser(string $eventId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM EventRelation WHERE eventId = ? AND userId = ?'
        );
        $stmt->execute([$eventId, $userId]);
    }

    private static function normalizeRole(string $role): string
    {
        return $role === 'leader' ? 'leader' : 'participant';
    }
}
