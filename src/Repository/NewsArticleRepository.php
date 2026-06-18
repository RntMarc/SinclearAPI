<?php

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class NewsArticleRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM NewsArticle WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByUrl(string $url): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM NewsArticle WHERE url = ?');
        $stmt->execute([$url]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(string $title, string $url, string $sourceName, ?string $sourceIcon): string
    {
        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO NewsArticle (id, title, url, sourceName, sourceIcon, savedAt)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $title, $url, $sourceName, $sourceIcon]);
        return $id;
    }

    public function list(int $page, int $limit, ?string $sourceName = null, ?int $maxAgeDays = null): array
    {
        $conditions = [];
        $params = [];

        if ($sourceName !== null) {
            $conditions[] = 'sourceName = ?';
            $params[] = $sourceName;
        }

        if ($maxAgeDays !== null) {
            $conditions[] = 'savedAt >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $maxAgeDays;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM NewsArticle $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            "SELECT * FROM NewsArticle $where ORDER BY savedAt DESC LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $limit, $offset]);

        return [
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function listArchive(int $page, int $limit): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT a.id)
             FROM NewsArticle a
             INNER JOIN NewsUpvote u ON u.articleId = a.id
             WHERE a.savedAt < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare(
            'SELECT a.*
             FROM NewsArticle a
             INNER JOIN NewsUpvote u ON u.articleId = a.id
             WHERE a.savedAt < DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY a.id
             ORDER BY a.savedAt DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->execute([$limit, $offset]);

        return [
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
