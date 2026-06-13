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
        $forums = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $forums], 200, $response);
    }

    /**
     * GET /forums/{id}/posts
     * Posts for a specific forum with visibility logic and vote counts.
     */
    public function forumPosts(ServerRequestInterface $request, ResponseInterface $response, string $forumId): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        // Get close friend IDs (users who marked current user as close friend)
        $cfStmt = $this->pdo->prepare("SELECT userId FROM CloseFriend WHERE friendId = ?");
        $cfStmt->execute([$user->id]);
        $closeFriendIds = array_column($cfStmt->fetchAll(), 'userId');

        // Build visibility conditions
        $visibilityParts = ['fp.visibility = 1', "fp.userId = ?"];
        $params = [$user->id];

        if (count($closeFriendIds) > 0) {
            $cfPlaceholders = implode(',', array_fill(0, count($closeFriendIds), '?'));
            $visibilityParts[] = "(fp.visibility = 2 AND fp.userId IN ({$cfPlaceholders}))";
            $params = array_merge($closeFriendIds, [$user->id]);
        }

        $visibilitySql = implode(' OR ', $visibilityParts);

        $sql = "SELECT fp.*, u.id AS userId, u.displayName, u.image,
                       (SELECT COUNT(*) FROM FeedPostVote WHERE postId = fp.id) AS voteCount,
                       EXISTS (SELECT 1 FROM FeedPostVote WHERE postId = fp.id AND userId = ?) AS hasVoted
                FROM FeedPosts fp
                INNER JOIN `User` u ON u.id = fp.userId
                WHERE fp.forumId = ? AND ({$visibilitySql})
                ORDER BY fp.createdAt DESC";

        $allParams = array_merge([$user->id, $forumId], $params);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($allParams);
        $posts = $stmt->fetchAll();

        // Enrich with canEdit
        $enriched = array_map(function ($post) use ($user) {
            $post['voteCount'] = (int) $post['voteCount'];
            $post['hasVoted'] = (bool) $post['hasVoted'];
            $post['canEdit'] = $post['userId'] === $user->id;
            return $post;
        }, $posts);

        return ResponseFactory::json(['data' => $enriched], 200, $response);
    }

    /**
     * GET /forums/{id}
     * Full forum detail: forum info, membership, members, posts.
     */
    public function forumDetail(ServerRequestInterface $request, ResponseInterface $response, string $forumId): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        // 1. Get Forum
        $stmt = $this->pdo->prepare("SELECT * FROM `Forum` WHERE id = ? LIMIT 1");
        $stmt->execute([$forumId]);
        $forum = $stmt->fetch();
        if (!$forum) {
            throw HttpException::notFound('Forum not found');
        }

        // 2. Membership check
        $memStmt = $this->pdo->prepare(
            "SELECT 1 FROM ForumMember WHERE forumId = ? AND userId = ? LIMIT 1"
        );
        $memStmt->execute([$forumId, $user->id]);
        $isMember = (bool) $memStmt->fetch();

        // 3. Members
        $membersStmt = $this->pdo->prepare(
            "SELECT u.id, u.displayName, u.image
             FROM ForumMember fm
             INNER JOIN `User` u ON u.id = fm.userId
             WHERE fm.forumId = ?"
        );
        $membersStmt->execute([$forumId]);
        $members = $membersStmt->fetchAll();

        // 4. Close friend IDs
        $cfStmt = $this->pdo->prepare("SELECT userId FROM CloseFriend WHERE friendId = ?");
        $cfStmt->execute([$user->id]);
        $closeFriendIds = array_column($cfStmt->fetchAll(), 'userId');

        // 5. Posts with visibility
        $visibilityParts = ['fp.visibility = 1', "fp.userId = ?"];
        $postParams = [$user->id];

        if (count($closeFriendIds) > 0) {
            $cfPlaceholders = implode(',', array_fill(0, count($closeFriendIds), '?'));
            $visibilityParts[] = "(fp.visibility = 2 AND fp.userId IN ({$cfPlaceholders}))";
            $postParams = array_merge($closeFriendIds, [$user->id]);
        }

        $visibilitySql = implode(' OR ', $visibilityParts);

        $postsSql = "SELECT fp.*, u.id AS userId, u.displayName, u.image,
                       (SELECT COUNT(*) FROM FeedPostVote WHERE postId = fp.id) AS voteCount,
                       EXISTS (SELECT 1 FROM FeedPostVote WHERE postId = fp.id AND userId = ?) AS hasVoted
                FROM FeedPosts fp
                INNER JOIN `User` u ON u.id = fp.userId
                WHERE fp.forumId = ? AND ({$visibilitySql})
                ORDER BY fp.createdAt DESC";

        $postsStmt = $this->pdo->prepare($postsSql);
        $postsStmt->execute(array_merge([$user->id, $forumId], $postParams));
        $posts = $postsStmt->fetchAll();

        $enriched = array_map(function ($post) use ($user) {
            $post['voteCount'] = (int) $post['voteCount'];
            $post['hasVoted'] = (bool) $post['hasVoted'];
            $post['canEdit'] = $post['userId'] === $user->id;
            return $post;
        }, $posts);

        return ResponseFactory::json([
            'forum' => $forum,
            'isMember' => $isMember,
            'members' => $members,
            'posts' => $enriched,
        ], 200, $response);
    }
}
