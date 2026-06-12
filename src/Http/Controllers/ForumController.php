<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class ForumController
{
    public function __construct(
        private readonly \PDO $pdo
    ) {
    }

    public function myForums(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.name, f.description, f.image, f.createdAt, f.updatedAt,
                    CAST((SELECT COUNT(*) FROM FeedPosts WHERE forumId = f.id) AS SIGNED) AS postCount,
                    EXISTS (
                      SELECT 1 FROM Notification n
                      INNER JOIN FeedPosts fp ON fp.id = n.entityId
                      WHERE n.userId = :uid1
                        AND n.type = 'forum'
                        AND fp.forumId = f.id
                    ) AS hasUnread
             FROM ForumMember fm
             INNER JOIN `Forum` f ON f.id = fm.forumId
             WHERE fm.userId = :uid2
             ORDER BY f.createdAt DESC"
        );
        $stmt->execute(['uid1' => $user->id, 'uid2' => $user->id]);
        );
        $stmt->execute(['userId' => $user->id]);
        $forums = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $forums], 200, $response);
    }
}
