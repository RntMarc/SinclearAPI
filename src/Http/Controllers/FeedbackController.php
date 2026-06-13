<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class FeedbackController
{
    public function __construct(
        private readonly \PDO $pdo
    ) {
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }

    /**
     * GET /feedback/list
     * List feedback suggestions with upvote counts and user info.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $sql = "SELECT fs.id, fs.userId, u.displayName AS userDisplayName, u.image AS userImage,
                       fs.title, fs.description, fs.status, fs.createdAt, fs.updatedAt,
                       COUNT(fv.id) AS upvotes,
                       MAX(CASE WHEN fv.userId = ? THEN 1 ELSE 0 END) AS hasUpvoted
                FROM FeedbackSuggestion fs
                LEFT JOIN `User` u ON u.id = fs.userId
                LEFT JOIN FeedbackVote fv ON fv.suggestionId = fs.id
                GROUP BY fs.id, fs.userId, u.displayName, u.image, fs.title, fs.description,
                         fs.status, fs.createdAt, fs.updatedAt
                ORDER BY COUNT(fv.id) DESC, fs.createdAt DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$user->id]);
        $suggestions = $stmt->fetchAll();

        $suggestions = array_map(function ($s) {
            $s['upvotes'] = (int) $s['upvotes'];
            $s['hasUpvoted'] = (bool) $s['hasUpvoted'];
            return $s;
        }, $suggestions);

        return ResponseFactory::json(['data' => $suggestions], 200, $response);
    }
}
