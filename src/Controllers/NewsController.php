<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\NewsService;

final readonly class NewsController
{
    public function __construct(
        private NewsService $newsService,
    ) {}

    public function listArticles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $sourceName = !empty($params['sourceName']) ? $params['sourceName'] : null;

        $result = $this->newsService->listArticles($page, $limit, $sourceName);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function listUserVotes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->newsService->listUserVotes($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function createVote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $url = trim($body['url'] ?? '');
        $title = trim($body['title'] ?? '');
        $sourceName = trim($body['sourceName'] ?? '');

        if ($url === '' || $title === '' || $sourceName === '') {
            return ResponseFactory::json(['error' => 'missing_fields'], 400, $response);
        }

        $sourceIcon = !empty($body['sourceIcon']) ? trim($body['sourceIcon']) : null;

        try {
            $result = $this->newsService->upvote($user->id, $url, $title, $sourceName, $sourceIcon);
            return ResponseFactory::json(['data' => $result], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => 'already_voted'], 409, $response);
        }
    }

    public function removeVote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $articleId = trim($body['articleId'] ?? '');
        if ($articleId === '') {
            return ResponseFactory::json(['error' => 'missing_articleId'], 400, $response);
        }

        $this->newsService->removeVote($user->id, $articleId);
        return ResponseFactory::noContent($response);
    }

    public function getVote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $voted = $this->newsService->getVoteStatus($user->id, $args['id']);
        return ResponseFactory::json(['data' => ['voted' => $voted]], 200, $response);
    }

    public function listArchive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->newsService->listArchive($page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function listSources(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sources = $this->newsService->listSources();
        return ResponseFactory::json(['data' => $sources], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
