<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class DiscoverPlaceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM DiscoverPlace WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByOsmId(int $osmId, string $osmType): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM DiscoverPlace WHERE osmId = ? AND osmType = ?');
        $stmt->execute([$osmId, $osmType]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO DiscoverPlace (id, name, description, category, address, latitude, longitude,
                                        osmId, osmType, phone, website, email, openingHours,
                                        lastUpdated, creatorId, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['description'] ?? null,
            $data['category'],
            $data['address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['osmId'],
            $data['osmType'],
            $data['phone'] ?? null,
            $data['website'] ?? null,
            $data['email'] ?? null,
            $data['openingHours'] ?? null,
            $data['creatorId'],
        ]);
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE DiscoverPlace
             SET name = ?, description = ?, category = ?, address = ?, latitude = ?, longitude = ?,
                 phone = ?, website = ?, email = ?, openingHours = ?, lastUpdated = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['category'],
            $data['address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['phone'] ?? null,
            $data['website'] ?? null,
            $data['email'] ?? null,
            $data['openingHours'] ?? null,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM DiscoverGastronomy WHERE placeId = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('DELETE FROM DiscoverPlace WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function list(?string $category, int $page, int $limit, ?string $sort = null): array
    {
        $conditions = '';
        $params = [];
        $joinReview = false;

        if ($category !== null) {
            $conditions = 'WHERE p.category = ?';
            $params[] = $category;
        }

        $orderMap = [
            'name_asc' => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'created_asc' => 'p.createdAt ASC',
            'created_desc' => 'p.createdAt DESC',
            'rating_asc' => 'avg_rating ASC',
            'rating_desc' => 'avg_rating DESC',
        ];

        $select = 'p.*';
        $from = 'FROM DiscoverPlace p';

        if ($sort !== null && in_array($sort, ['rating_asc', 'rating_desc'], true)) {
            $joinReview = true;
            $from .= ' LEFT JOIN DiscoverReview r ON r.placeId = p.id';
            $select .= ', ROUND(AVG(r.rating), 1) AS avg_rating';
        }

        $groupBy = $joinReview ? ' GROUP BY p.id' : '';

        $orderBy = $sort !== null && isset($orderMap[$sort])
            ? $orderMap[$sort]
            : 'p.createdAt DESC';

        $countFrom = 'FROM DiscoverPlace p';
        $countSelect = 'COUNT(*)';
        if ($joinReview) {
            $countFrom .= ' LEFT JOIN DiscoverReview r ON r.placeId = p.id';
            $countSelect = 'COUNT(DISTINCT p.id)';
        }

        $countStmt = $this->pdo->prepare("SELECT $countSelect $countFrom $conditions");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT $select $from $conditions $groupBy ORDER BY $orderBy LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);
        $places = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $places,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function random(int $limit, ?string $category = null): array
    {
        $conditions = '';
        $params = [];

        if ($category !== null) {
            $conditions = 'WHERE category = ?';
            $params[] = $category;
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM DiscoverPlace $conditions ORDER BY RAND() LIMIT ?"
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(array $params): array
    {
        $conditions = [];
        $bindings = [];
        $haversine = null;

        if (!empty($params['q'])) {
            $conditions[] = 'p.name LIKE ?';
            $bindings[] = '%' . $params['q'] . '%';
        }

        if (!empty($params['category'])) {
            $conditions[] = 'p.category = ?';
            $bindings[] = $params['category'];
        }

        if (isset($params['lat']) && isset($params['lon']) && isset($params['radius'])) {
            $lat = (float) $params['lat'];
            $lon = (float) $params['lon'];
            $radius = (int) $params['radius'];

            $haversine = "(
                6371000 * acos(cos(radians($lat)) * cos(radians(p.latitude))
                * cos(radians(p.longitude) - radians($lon)) + sin(radians($lat))
                * sin(radians(p.latitude)))
            )";

            $conditions[] = "$haversine < ?";
            $bindings[] = $radius;
        }

        $fromClause = 'FROM DiscoverPlace p';
        if (!empty($params['cuisine'])) {
            $fromClause .= ' JOIN DiscoverGastronomy g ON g.placeId = p.id';
            $conditions[] = 'g.cuisine = ?';
            $bindings[] = $params['cuisine'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) $fromClause $where");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $select = 'p.*';
        $orderBy = 'p.createdAt DESC';

        if ($haversine !== null) {
            $select .= ", $haversine AS distance";
            $orderBy = 'distance ASC';
        }

        if (!empty($params['cuisine'])) {
            $select .= ', g.cuisine';
        }

        $dataStmt = $this->pdo->prepare(
            "SELECT $select $fromClause $where ORDER BY $orderBy LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$bindings, $limit, $offset]);
        $places = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $places,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
