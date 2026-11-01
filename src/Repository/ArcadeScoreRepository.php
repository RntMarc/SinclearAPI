<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class ArcadeScoreRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param array<string, mixed>|null $meta
     */
    public function create(string $userId, string $gameId, int $score, ?array $meta): string
    {
        $id = Uuid::uuid7()->toString();
        $metaJson = $meta !== null
            ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO ArcadeScore (id, userId, gameId, score, meta, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$id, $userId, $gameId, $score, $metaJson]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, gameId, score, meta, createdAt FROM ArcadeScore WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function listByGameAndUser(string $gameId, string $userId, int $limit, int $offset): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ArcadeScore WHERE gameId = ? AND userId = ?'
        );
        $countStmt->execute([$gameId, $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, userId, gameId, score, meta, createdAt
             FROM ArcadeScore
             WHERE gameId = ? AND userId = ?
             ORDER BY createdAt DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$gameId, $userId, $limit, $offset]);

        return [
            'data' => array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC))),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findBestForUser(string $gameId, string $userId, string $direction): ?array
    {
        $direction = $direction === 'asc' ? 'ASC' : 'DESC';

        $stmt = $this->pdo->prepare(
            'SELECT id, userId, gameId, score, meta, createdAt
             FROM ArcadeScore
             WHERE gameId = ? AND userId = ?
             ORDER BY score ' . $direction . ', createdAt ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([$gameId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Bestwert je Nutzer (nur existierende User), sortiert nach Spielrichtung.
     *
     * @return list<array<string, mixed>>
     */
    public function listLeaderboard(string $gameId, string $direction, int $limit, int $offset): array
    {
        $desc = $direction !== 'asc';
        $sqlDirection = $desc ? 'DESC' : 'ASC';

        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.userId, b.score, b.meta, b.createdAt, u.displayName, u.image
             FROM ArcadeScore b
             INNER JOIN User u ON u.id = b.userId
             WHERE b.gameId = ? AND ' . self::bestRowCondition($desc) . '
             ORDER BY b.score ' . $sqlDirection . ', b.createdAt ASC, b.userId ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$gameId, $limit, $offset]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function countLeaderboard(string $gameId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT b.userId)
             FROM ArcadeScore b
             INNER JOIN User u ON u.id = b.userId
             WHERE b.gameId = ?'
        );
        $stmt->execute([$gameId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Anzahl der Nutzer, deren Bestwert vor dem uebergebenen Bestwert liegt.
     */
    public function countAhead(
        string $gameId,
        string $direction,
        int $score,
        string $createdAt,
        string $userId,
    ): int {
        $desc = $direction !== 'asc';

        $beatOwn = $desc
            ? '(b.score > ? OR (b.score = ? AND (b.createdAt < ? OR (b.createdAt = ? AND b.userId < ?))))'
            : '(b.score < ? OR (b.score = ? AND (b.createdAt < ? OR (b.createdAt = ? AND b.userId < ?))))';

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM ArcadeScore b
             INNER JOIN User u ON u.id = b.userId
             WHERE b.gameId = ? AND ' . self::bestRowCondition($desc) . ' AND ' . $beatOwn
        );
        $stmt->execute([$gameId, $score, $score, $createdAt, $createdAt, $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Anti-Join: b ist der Bestwert seines Nutzers (Richtung + fruehester
     * Zeitpunkt + kleinste ID als deterministischer Tie-Break).
     */
    private static function bestRowCondition(bool $desc): string
    {
        $better = $desc
            ? '(x.score > b.score OR (x.score = b.score AND (x.createdAt < b.createdAt OR (x.createdAt = b.createdAt AND x.id < b.id))))'
            : '(x.score < b.score OR (x.score = b.score AND (x.createdAt < b.createdAt OR (x.createdAt = b.createdAt AND x.id < b.id))))';

        return 'NOT EXISTS (
            SELECT 1 FROM ArcadeScore x
            WHERE x.gameId = b.gameId AND x.userId = b.userId AND ' . $better . '
        )';
    }

    public function deleteByGameId(string $gameId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ArcadeScore WHERE gameId = ?');
        $stmt->execute([$gameId]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['score'] = (int) $row['score'];

        if (isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== '') {
            $decoded = json_decode($row['meta'], true);
            $row['meta'] = is_array($decoded) ? $decoded : null;
        } elseif (array_key_exists('meta', $row)) {
            $row['meta'] = null;
        }

        return $row;
    }
}
