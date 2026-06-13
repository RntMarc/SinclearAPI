<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\NewsService;

final class NewsController
{
    public function __construct(
        private readonly NewsService $newsService
    ) {
    }

    private function getUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }

    // ── RSS Sources ─────────────────────────────────────────────────────────

    public function listRssSources(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json(['data' => $this->newsService->getRssSources()], 200, $response);
    }

    public function createRssSource(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $source = $this->newsService->createRssSource($body);
        return ResponseFactory::json(['data' => $source], 201, $response);
    }

    public function updateRssSource(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '') throw HttpException::badRequest('missing_id');
        $body = (array) $request->getParsedBody();
        $this->newsService->updateRssSource($id, $body);
        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function deleteRssSource(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '') throw HttpException::badRequest('missing_id');
        $this->newsService->deleteRssSource($id);
        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    // ── News ────────────────────────────────────────────────────────────────

    public function important(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json(['data' => $this->newsService->getImportantNews()], 200, $response);
    }

    public function archived(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json(['data' => $this->newsService->getArchivedNews()], 200, $response);
    }

    public function upvoteArticle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getUser($request);
        $body = (array) $request->getParsedBody();
        $this->newsService->upvoteArticle($user, $body);
        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function upvotedUrls(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getUser($request);
        $urls = $this->newsService->getUpvotedArticleUrls($user);
        return ResponseFactory::json(['data' => $urls], 200, $response);
    }

    public function upvoteCounts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json(['data' => $this->newsService->getUpvoteCounts()], 200, $response);
    }
}
