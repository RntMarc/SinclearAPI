<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class EventService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function create(AuthenticatedUser $user, array $data): array
    {
        $id = Uuid::uuid4()->toString();
        $now = new \DateTime();

        $stmt = $this->pdo->prepare(
            'INSERT INTO `Event` (id, title, description, startAt, endAt, allDay, isPublic, createdAt, creatorId)
             VALUES (:id, :title, :description, :startAt, :endAt, :allDay, :isPublic, :createdAt, :creatorId)'
        );
        $stmt->execute([
            'id' => $id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'startAt' => $data['startAt'],
            'endAt' => $data['endAt'] ?? null,
            'allDay' => isset($data['allDay']) ? ($data['allDay'] ? 1 : 0) : 0,
            'isPublic' => isset($data['isPublic']) ? ($data['isPublic'] ? 1 : 0) : 1,
            'createdAt' => $now->format('Y-m-d H:i:s'),
            'creatorId' => $user->id,
        ]);

        return $this->findById($id);
    }

    public function update(AuthenticatedUser $user, string $id, array $data): array
    {
        $event = $this->findById($id);
        if ($event === null) {
            throw HttpException::notFound();
        }
        $this->requireEditPermission($user, $event);

        $fields = [];
        $params = ['id' => $id];

        foreach (['title', 'description', 'startAt', 'endAt', 'allDay', 'isPublic'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = :$field";
                if ($field === 'allDay' || $field === 'isPublic') {
                    $params[$field] = $data[$field] ? 1 : 0;
                } else {
                    $params[$field] = $data[$field];
                }
            }
        }

        if ($fields !== []) {
            $sql = 'UPDATE `Event` SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $this->pdo->prepare($sql)->execute($params);
        }

        return $this->findById($id);
    }

    public function delete(AuthenticatedUser $user, string $id): void
    {
        $event = $this->findById($id);
        if ($event === null) return;

        $this->requireEditPermission($user, $event);

        $this->pdo->prepare('DELETE FROM EventPermission WHERE eventId = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM `Event` WHERE id = :id')->execute(['id' => $id]);
    }

    public function getPermissions(AuthenticatedUser $user, string $eventId): array
    {
        $event = $this->findById($eventId);
        if ($event === null) {
            throw HttpException::notFound();
        }
        $this->requireEditPermission($user, $event);

        $stmt = $this->pdo->prepare(
            'SELECT ep.userId, ep.canView, ep.canEdit, u.displayName, u.email
             FROM EventPermission ep
             INNER JOIN `User` u ON u.id = ep.userId
             WHERE ep.eventId = :eventId'
        );
        $stmt->execute(['eventId' => $eventId]);
        return $stmt->fetchAll();
    }

    public function setPermissions(AuthenticatedUser $user, string $eventId, array $permissions): void
    {
        $event = $this->findById($eventId);
        if ($event === null) {
            throw HttpException::notFound();
        }
        $this->requireEditPermission($user, $event);

        $this->pdo->prepare('DELETE FROM EventPermission WHERE eventId = :id')->execute(['id' => $eventId]);

        if ($permissions === []) return;

        $now = new \DateTime();
        $stmt = $this->pdo->prepare(
            'INSERT INTO EventPermission (id, eventId, userId, canView, canEdit, createdAt)
             VALUES (:id, :eventId, :userId, :canView, :canEdit, :createdAt)'
        );
        foreach ($permissions as $perm) {
            $stmt->execute([
                'id' => Uuid::uuid4()->toString(),
                'eventId' => $eventId,
                'userId' => $perm['userId'],
                'canView' => isset($perm['canView']) ? ($perm['canView'] ? 1 : 0) : 1,
                'canEdit' => isset($perm['canEdit']) ? ($perm['canEdit'] ? 1 : 0) : 0,
                'createdAt' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function requireEditPermission(AuthenticatedUser $user, array $event): void
    {
        if ($user->isAdmin) return;
        if ((string) $event['creatorId'] === $user->id) return;

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM EventPermission WHERE eventId = :eventId AND userId = :userId AND canEdit = 1 LIMIT 1'
        );
        $stmt->execute(['eventId' => $event['id'], 'userId' => $user->id]);
        if (!$stmt->fetch()) {
            throw HttpException::forbidden();
        }
    }

    private function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `Event` WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
