<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Planungsteilnehmer (TravelPlanMember) – bewusst getrennt von
 * TravelRelation, damit mitplanende Personen keinen Zugriff auf operative
 * Reise-Objekte erhalten.
 *
 * Status: invited | accepted | declined | inactive
 * Rolle:  leader | member
 */
final readonly class TravelPlanMemberRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function findByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id AS memberId, m.tripId, m.userId, m.status, m.role, m.origin,
                    m.createdAt, m.updatedAt, m.deactivatedAt,
                    u.email, u.displayName, u.image
             FROM TravelPlanMember m
             JOIN User u ON u.id = m.userId
             WHERE m.tripId = ?
             ORDER BY (m.role = 'leader') DESC, u.displayName ASC"
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findActiveByTrip(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id AS memberId, m.tripId, m.userId, m.status, m.role, m.origin,
                    m.createdAt, m.updatedAt, m.deactivatedAt,
                    u.email, u.displayName, u.image
             FROM TravelPlanMember m
             JOIN User u ON u.id = m.userId
             WHERE m.tripId = ? AND m.status <> 'inactive'
             ORDER BY (m.role = 'leader') DESC, u.displayName ASC"
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByTripAndUser(string $tripId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM TravelPlanMember WHERE tripId = ? AND userId = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(
        string $tripId,
        string $userId,
        string $status = 'invited',
        string $role = 'member',
        ?string $origin = null,
    ): string {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO TravelPlanMember (id, tripId, userId, status, role, origin)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tripId, $userId, $status, self::normalizeRole($role), $origin]);
        return $id;
    }

    /**
     * Rennsichere Einladung: legt die Mitgliedschaft an oder setzt eine
     * bestehende (z. B. zuvor inaktive) auf 'invited' zurueck. Die Rolle bleibt
     * erhalten, damit eine erneut eingeladene Leitung nicht zur einfachen
     * Mitgliedschaft degradiert wird.
     */
    public function invite(string $tripId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO TravelPlanMember (id, tripId, userId, status, role, origin)
             VALUES (?, ?, ?, 'invited', 'member', 'invite')
             ON DUPLICATE KEY UPDATE status = 'invited', deactivatedAt = NULL"
        );
        $stmt->execute([Uuid::uuid7()->toString(), $tripId, $userId]);
    }

    public function updateStatus(string $tripId, string $userId, string $status): void
    {
        $deactivatedAt = $status === 'inactive' ? 'NOW(3)' : 'NULL';
        $stmt = $this->pdo->prepare(
            "UPDATE TravelPlanMember SET status = ?, deactivatedAt = $deactivatedAt
             WHERE tripId = ? AND userId = ?"
        );
        $stmt->execute([$status, $tripId, $userId]);
    }

    public function updateRole(string $tripId, string $userId, string $role): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE TravelPlanMember SET role = ? WHERE tripId = ? AND userId = ?'
        );
        $stmt->execute([self::normalizeRole($role), $tripId, $userId]);
    }

    public function countLeaders(string $tripId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM TravelPlanMember
             WHERE tripId = ? AND role = 'leader' AND status <> 'inactive'"
        );
        $stmt->execute([$tripId]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteByTrip(string $tripId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM TravelPlanMember WHERE tripId = ?');
        $stmt->execute([$tripId]);
    }

    private static function normalizeRole(string $role): string
    {
        return $role === 'leader' ? 'leader' : 'member';
    }
}
