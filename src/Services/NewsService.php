<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\NewsArticleRepository;
use Sinclear\Api\Repository\NewsUpvoteRepository;
use Sinclear\Api\Repository\RssSourceRepository;

final readonly class NewsService
{
    public function __construct(
        private NewsArticleRepository $articleRepo,
        private NewsUpvoteRepository $upvoteRepo,
        private RssSourceRepository $sourceRepo,
        private RssFeedService $rssFeedService,
    ) {}

    public function listArticles(int $page, int $limit, ?string $sourceName = null): array
    {
        $dbResult = $this->articleRepo->list($page, $limit, $sourceName, maxAgeDays: 7);
        $dbResult['data'] = array_map(fn(array $a) => $this->formatArticle($a), $dbResult['data']);

        $sources = $this->sourceRepo->listAll();
        if ($sourceName !== null) {
            $sources = array_values(array_filter(
                $sources,
                fn(array $s) => $s['name'] === $sourceName,
            ));
        }

        $rssArticles = $this->rssFeedService->fetchAll($sources);

        return [
            'data' => $dbResult['data'],
            'rss' => $rssArticles,
            'meta' => $dbResult['meta'] + ['rssCount' => count($rssArticles)],
        ];
    }

    public function listArchive(int $page, int $limit): array
    {
        $result = $this->articleRepo->listArchive($page, $limit);
        $result['data'] = array_map(fn(array $a) => $this->formatArticle($a), $result['data']);
        return $result;
    }

    public function listSources(): array
    {
        return $this->sourceRepo->listAll();
    }

    public function listUserVotes(string $userId, int $page, int $limit): array
    {
        $result = $this->upvoteRepo->listByUser($userId, $page, $limit);
        $result['data'] = array_map(fn(array $a) => $this->formatArticle($a), $result['data']);
        return $result;
    }

    public function getVoteStatus(string $userId, string $articleId): bool
    {
        return $this->upvoteRepo->find($userId, $articleId) !== null;
    }

    public function upvote(string $userId, string $url, string $title, string $sourceName, ?string $sourceIcon): array
    {
        $article = $this->articleRepo->findByUrl($url);

        if ($article === null) {
            $articleId = $this->articleRepo->create($title, $url, $sourceName, $sourceIcon);
        } else {
            $articleId = $article['id'];
        }

        $existing = $this->upvoteRepo->find($userId, $articleId);
        if ($existing !== null) {
            throw new \RuntimeException('Already voted');
        }

        $voteId = $this->upvoteRepo->create($userId, $articleId);
        return ['id' => $voteId, 'articleId' => $articleId, 'voted' => true];
    }

    public function removeVote(string $userId, string $articleId): void
    {
        $this->upvoteRepo->delete($userId, $articleId);
    }

    private function formatArticle(array $article): array
    {
        return [
            'id' => $article['id'],
            'title' => $article['title'],
            'url' => $article['url'],
            'sourceName' => $article['sourceName'],
            'sourceIcon' => $article['sourceIcon'],
            'savedAt' => $article['savedAt'],
        ];
    }
}
