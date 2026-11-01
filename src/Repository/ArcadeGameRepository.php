<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ArcadeGameRepository
{
    private const string COLUMNS = 'id, slug, title, description, thumbnailUrl, scoreDirection, isActive, sortOrder, createdAt, updatedAt';

    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeGame
             WHERE isActive = 1
             ORDER BY sortOrder ASC, title ASC'
        );
        $stmt->execute();

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeGame
             ORDER BY sortOrder ASC, title ASC'
        );
        $stmt->execute();

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeGame WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeGame WHERE slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ArcadeGame
             WHERE slug = ? AND isActive = 1
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ArcadeGame WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ArcadeGame WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO ArcadeGame
                (id, slug, title, description, thumbnailUrl, scoreDirection, isActive, sortOrder, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        $stmt->execute([
            $id,
            $data['slug'],
            $data['title'],
            $data['description'],
            $data['thumbnailUrl'],
            $data['scoreDirection'],
            $data['isActive'] ? 1 : 0,
            $data['sortOrder'],
        ]);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void
    {
        $allowed = [
            'slug' => 'slug',
            'title' => 'title',
            'description' => 'description',
            'thumbnailUrl' => 'thumbnailUrl',
            'scoreDirection' => 'scoreDirection',
            'isActive' => 'isActive',
            'sortOrder' => 'sortOrder',
        ];

        $sets = [];
        $values = [];
        foreach ($allowed as $field => $column) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = $column . ' = ?';
            $value = $data[$field];
            if ($field === 'isActive') {
                $value = $value ? 1 : 0;
            }
            $values[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE ArcadeGame SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->execute($values);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ArcadeGame WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['isActive'] = (bool) ($row['isActive'] ?? false);
        $row['sortOrder'] = (int) ($row['sortOrder'] ?? 0);

        return $row;
    }
}
