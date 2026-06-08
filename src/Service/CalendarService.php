<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Aggregates calendar events and travel events.
 */
final class CalendarService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function combined(AuthenticatedUser $user): array
    {
        $events = $this->pdo->prepare(
            'SELECT e.*, "event" as source FROM `Event` e
             LEFT JOIN `EventPermission` ep ON ep.eventId = e.id AND ep.userId = :userId
             WHERE e.isPublic = 1 OR e.creatorId = :userId2 OR (ep.canView = 1)
             ORDER BY e.startAt ASC'
        );
        $events->execute(['userId' => $user->id, 'userId2' => $user->id]);
        $eventRows = $events->fetchAll();

        $travel = [];
        if ($user->isAdmin) {
            $travelStmt = $this->pdo->query(
                'SELECT te.*, "travel" as source FROM `TravelEvent` te ORDER BY te.start ASC'
            );
            $travel = $travelStmt ? $travelStmt->fetchAll() : [];
        }

        return array_merge($eventRows, $travel);
    }
}
