<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class SocialInfoUpdateRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @param array<string, mixed> $data */
    public function upsert(string $userId, array $data): void
    {
        $existing = $this->findByUserId($userId);

        if ($existing === null) {
            $id = Uuid::uuid7()->toString();
            $fields = ['id', 'userId'];
            $placeholders = ['?', '?'];
            $values = [$id, $userId];

            foreach ($data as $field => $value) {
                $fields[] = $field;
                $placeholders[] = '?';
                $values[] = $value;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO SocialInfo (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($values);
        } else {
            $sets = [];
            $values = [];
            foreach ($data as $field => $value) {
                $sets[] = "$field = ?";
                $values[] = $value;
            }
            $values[] = $userId;

            $stmt = $this->pdo->prepare(
                'UPDATE SocialInfo SET ' . implode(', ', $sets) . ' WHERE userId = ?'
            );
            $stmt->execute($values);
        }
    }

    private function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM SocialInfo WHERE userId = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
}
