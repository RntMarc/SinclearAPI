<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class NewsService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    // ── RSS Sources ─────────────────────────────────────────────────────────

    public function getRssSources(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, url, itemsPerPage, createdAt FROM RssSource ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function createRssSource(array $data): array
    {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO RssSource (id, name, url, itemsPerPage, createdAt) VALUES (:id, :name, :url, :itemsPerPage, :createdAt)'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'url' => $data['url'],
            'itemsPerPage' => $data['itemsPerPage'] ?? 10,
            'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        return $this->findRssSourceById($id);
    }

    public function updateRssSource(string $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['name', 'url', 'itemsPerPage'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = :$field";
                $params[$field] = $data[$field];
            }
        }
        if ($fields === []) return;
        $this->pdo->prepare('UPDATE RssSource SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
    }

    public function deleteRssSource(string $id): void
    {
        $this->pdo->prepare('DELETE FROM RssSource WHERE id = :id')->execute(['id' => $id]);
    }

    // ── News ────────────────────────────────────────────────────────────────

    public function getImportantNews(): array
    {
        $stmt = $this->pdo->query(
            "SELECT na.id, na.title, na.url, na.sourceName, na.sourceIcon, na.savedAt,
                    CAST(COUNT(nu.id) AS SIGNED) AS upvoteCount
             FROM NewsArticle na
             LEFT JOIN NewsUpvote nu ON nu.articleId = na.id
             WHERE na.savedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY na.id
             ORDER BY upvoteCount DESC, na.savedAt DESC"
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getArchivedNews(): array
    {
        $stmt = $this->pdo->query(
            "SELECT na.id, na.title, na.url, na.sourceName, na.sourceIcon, na.savedAt,
                    CAST(COUNT(nu.id) AS SIGNED) AS upvoteCount
             FROM NewsArticle na
             LEFT JOIN NewsUpvote nu ON nu.articleId = na.id
             WHERE na.savedAt < DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY na.id
             ORDER BY na.savedAt DESC"
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function upvoteArticle(AuthenticatedUser $user, array $data): void
    {
        $url = $data['link'] ?? $data['url'] ?? '';
        if ($url === '') {
            throw HttpException::badRequest('missing_url', 'Article URL is required');
        }

        // Find or create article
        $stmt = $this->pdo->prepare('SELECT id FROM NewsArticle WHERE url = :url LIMIT 1');
        $stmt->execute(['url' => $url]);
        $article = $stmt->fetch();

        if ($article) {
            $articleId = $article['id'];
        } else {
            $articleId = Uuid::uuid4()->toString();
            $insertStmt = $this->pdo->prepare(
                'INSERT INTO NewsArticle (id, title, url, sourceName, sourceIcon, savedAt)
                 VALUES (:id, :title, :url, :sourceName, :sourceIcon, :savedAt)'
            );
            $insertStmt->execute([
                'id' => $articleId,
                'title' => $data['title'] ?? '',
                'url' => $url,
                'sourceName' => $data['sourceName'] ?? '',
                'sourceIcon' => $data['sourceIcon'] ?? '',
                'savedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        }

        // Check existing upvote
        $checkStmt = $this->pdo->prepare(
            'SELECT id FROM NewsUpvote WHERE articleId = :articleId AND userId = :userId LIMIT 1'
        );
        $checkStmt->execute(['articleId' => $articleId, 'userId' => $user->id]);

        if (!$checkStmt->fetch()) {
            $upvoteStmt = $this->pdo->prepare(
                'INSERT INTO NewsUpvote (id, articleId, userId, createdAt) VALUES (:id, :articleId, :userId, :createdAt)'
            );
            $upvoteStmt->execute([
                'id' => Uuid::uuid4()->toString(),
                'articleId' => $articleId,
                'userId' => $user->id,
                'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function getUpvotedArticleUrls(AuthenticatedUser $user): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT na.url FROM NewsArticle na
             INNER JOIN NewsUpvote nu ON nu.articleId = na.id
             WHERE nu.userId = :userId'
        );
        $stmt->execute(['userId' => $user->id]);
        $rows = $stmt->fetchAll();
        return array_map(static fn (array $r): string => $r['url'], $rows);
    }

    public function getUpvoteCounts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT na.url, CAST(COUNT(nu.id) AS SIGNED) AS count
             FROM NewsArticle na
             LEFT JOIN NewsUpvote nu ON nu.articleId = na.id
             GROUP BY na.url"
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['url']] = (int) $row['count'];
        }
        return $counts;
    }

    private function findRssSourceById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM RssSource WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
