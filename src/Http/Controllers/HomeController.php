<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class HomeController
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
     * GET /home/media-reviews
     * Recent media reviews with item and user info.
     */
    public function recentMediaReviews(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $days = (int) ($request->getQueryParams()['days'] ?? 7);

        $stmt = $this->pdo->prepare(
            "SELECT mr.*, mi.id AS itemId, mi.title AS itemTitle, mi.image AS itemImage, mi.type AS itemType,
                    u.id AS userId, u.displayName, u.image
             FROM MediaReview mr
             INNER JOIN MediaItem mi ON mi.id = mr.itemId
             INNER JOIN `User` u ON u.id = mr.userId
             WHERE mr.createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY mr.createdAt DESC
             LIMIT 10"
        );
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $rows], 200, $response);
    }

    /**
     * GET /home/discover-reviews
     * Recent discover reviews with place and user info.
     */
    public function recentDiscoverReviews(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $days = (int) ($request->getQueryParams()['days'] ?? 7);

        $stmt = $this->pdo->prepare(
            "SELECT dr.*, dp.id AS placeId, dp.name AS placeName,
                    u.id AS userId, u.displayName, u.image
             FROM DiscoverReview dr
             INNER JOIN DiscoverPlace dp ON dp.id = dr.placeId
             INNER JOIN `User` u ON u.id = dr.userId
             WHERE dr.createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY dr.createdAt DESC
             LIMIT 10"
        );
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $rows], 200, $response);
    }

    /**
     * GET /home/polls
     * Active and finalized polls for the homepage.
     * Returns { active: [...], finalized: [...] }
     */
    public function homePolls(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        // Get invited poll IDs
        $invStmt = $this->pdo->prepare("SELECT pollId FROM PollInvite WHERE userId = ?");
        $invStmt->execute([$user->id]);
        $invitedPollIds = array_column($invStmt->fetchAll(), 'pollId');

        // Build active polls (creator or invited, not finalized)
        $activePolls = [];
        $placeholders = '';
        $params = [];

        if (count($invitedPollIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($invitedPollIds), '?'));
            $sql = "SELECT p.id, p.title, p.creatorId, p.finalizedOptionId, u.displayName AS creatorName
                    FROM `Poll` p
                    LEFT JOIN `User` u ON u.id = p.creatorId
                    WHERE (p.creatorId = ? OR p.id IN ({$placeholders}))
                      AND p.finalizedOptionId IS NULL
                    ORDER BY p.createdAt DESC";
            $params = array_merge([$user->id], $invitedPollIds);
        } else {
            $sql = "SELECT p.id, p.title, p.creatorId, p.finalizedOptionId, u.displayName AS creatorName
                    FROM `Poll` p
                    LEFT JOIN `User` u ON u.id = p.creatorId
                    WHERE p.creatorId = ?
                      AND p.finalizedOptionId IS NULL
                    ORDER BY p.createdAt DESC";
            $params = [$user->id];
        }

        $actStmt = $this->pdo->prepare($sql);
        $actStmt->execute($params);
        $activePolls = $actStmt->fetchAll();

        // Build finalized polls (creator or invited, finalized, within 7 days, future date option)
        $finalizedPolls = [];
        if (count($invitedPollIds) > 0) {
            $sqlFin = "SELECT p.id, p.title, p.finalizedOptionId, po.id AS optionId, po.label, po.dateValue, po.orderNum, po.questionId
                       FROM `Poll` p
                       INNER JOIN PollOption po ON po.id = p.finalizedOptionId
                       WHERE (p.creatorId = ? OR p.id IN ({$placeholders}))
                         AND p.finalizedOptionId IS NOT NULL
                         AND p.updatedAt >= ?
                         AND po.dateValue >= NOW()
                       ORDER BY p.updatedAt DESC";
            $paramsFin = array_merge([$user->id], $invitedPollIds, [$sevenDaysAgo]);
        } else {
            $sqlFin = "SELECT p.id, p.title, p.finalizedOptionId, po.id AS optionId, po.label, po.dateValue, po.orderNum, po.questionId
                       FROM `Poll` p
                       INNER JOIN PollOption po ON po.id = p.finalizedOptionId
                       WHERE p.creatorId = ?
                         AND p.finalizedOptionId IS NOT NULL
                         AND p.updatedAt >= ?
                         AND po.dateValue >= NOW()
                       ORDER BY p.updatedAt DESC";
            $paramsFin = [$user->id, $sevenDaysAgo];
        }

        $finStmt = $this->pdo->prepare($sqlFin);
        $finStmt->execute($paramsFin);
        $finalizedPolls = $finStmt->fetchAll();

        return ResponseFactory::json([
            'data' => [
                'active' => $activePolls,
                'finalized' => $finalizedPolls,
            ],
        ], 200, $response);
    }

    /**
     * GET /home/feed-posts
     * All recent posts from forums the user is a member of.
     * Each post includes user info, vote count, and visibility check.
     */
    public function homeFeedPosts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $days = (int) ($request->getQueryParams()['days'] ?? 7);

        // Get user IDs who marked the current user as close friend
        $cfStmt = $this->pdo->prepare(
            "SELECT userId FROM CloseFriend WHERE friendId = ?"
        );
        $cfStmt->execute([$user->id]);
        $closeFriendIds = array_column($cfStmt->fetchAll(), 'userId');

        // Build the visibility condition
        // visibility=1 (public) OR visibility=2 AND author is close friend OR own post
        $visibilityParts = ['fp.visibility = 1', 'fp.userId = ?'];
        $params = [$user->id];

        if (count($closeFriendIds) > 0) {
            $cfPlaceholders = implode(',', array_fill(0, count($closeFriendIds), '?'));
            $visibilityParts[] = "(fp.visibility = 2 AND fp.userId IN ({$cfPlaceholders}))";
            $params = array_merge([$user->id], $closeFriendIds, [$user->id]);
        } else {
            $params = [$user->id, $user->id];
        }

        $visibilitySql = implode(' OR ', $visibilityParts);

        // Get all posts from joined forums within the last N days
        $sql = "SELECT fp.id, fp.content, fp.category, fp.visibility, fp.forumId, fp.userId AS authorId,
                       fp.createdAt, fp.updatedAt,
                       fp.artist, fp.title AS musicTitle, fp.spotifyUrl, fp.youtubeMusicUrl,
                       fp.youtubeUrl, fp.soundcloudUrl, fp.videoUrl, fp.videoPlatform,
                       fp.newsTitle, fp.newsSite, fp.newsUrl, fp.otherTitle, fp.otherUrl,
                       u.displayName AS authorName, u.image AS authorImage,
                       (SELECT COUNT(*) FROM FeedPostVote WHERE postId = fp.id) AS voteCount,
                       EXISTS (SELECT 1 FROM FeedPostVote WHERE postId = fp.id AND userId = ?) AS hasVoted
                FROM FeedPosts fp
                INNER JOIN `User` u ON u.id = fp.userId
                INNER JOIN ForumMember fm ON fm.forumId = fp.forumId AND fm.userId = ?
                WHERE ({$visibilitySql})
                  AND fp.createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY fp.createdAt DESC
                LIMIT 50";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        // Enrich posts with canEdit flag
        $enriched = array_map(function ($post) use ($user) {
            $post['voteCount'] = (int) $post['voteCount'];
            $post['hasVoted'] = (bool) $post['hasVoted'];
            $post['canEdit'] = $post['authorId'] === $user->id;
            $post['user'] = [
                'id' => $post['authorId'],
                'displayName' => $post['authorName'],
                'image' => $post['authorImage'],
            ];
            unset($post['authorName'], $post['authorImage']);
            return $post;
        }, $posts);

        return ResponseFactory::json(['data' => $enriched], 200, $response);
    }

    /**
     * GET /home/feed-posts-list
     * Full feed post listing with optional category and onlyCloseFriends filters.
     * Used by the feed page (/api/posts).
     */
    public function feedPostsList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $queryParams = $request->getQueryParams();
        $category = $queryParams['category'] ?? null;
        $onlyCloseFriends = ($queryParams['onlyCloseFriends'] ?? 'false') === 'true';

        // Get user IDs who marked the current user as close friend (visibility=2 posts visible to me)
        $cfStmt = $this->pdo->prepare("SELECT userId FROM CloseFriend WHERE friendId = ?");
        $cfStmt->execute([$user->id]);
        $closeFriendIds = array_column($cfStmt->fetchAll(), 'userId');

        // Get my close friend IDs (for the onlyCloseFriends UI filter)
        $myCfStmt = $this->pdo->prepare("SELECT friendId FROM CloseFriend WHERE userId = ?");
        $myCfStmt->execute([$user->id]);
        $myCloseFriendIds = array_column($myCfStmt->fetchAll(), 'friendId');

        // Build visibility conditions
        $visibilityParts = ['fp.visibility = 1', "fp.userId = ?"];
        $params = [$user->id];

        if (count($closeFriendIds) > 0) {
            $cfPlaceholders = implode(',', array_fill(0, count($closeFriendIds), '?'));
            $visibilityParts[] = "(fp.visibility = 2 AND fp.userId IN ({$cfPlaceholders}))";
            $params = array_merge($closeFriendIds, [$user->id]);
        }

        $whereConditions = ['(' . implode(' OR ', $visibilityParts) . ')'];

        // Category filter
        if ($category && $category !== 'all') {
            $whereConditions[] = 'fp.category = ?';
            $params[] = $category;
        }

        // onlyCloseFriends UI filter
        if ($onlyCloseFriends) {
            if (count($myCloseFriendIds) > 0) {
                $cfFilterPlaceholders = implode(',', array_fill(0, count($myCloseFriendIds), '?'));
                $whereConditions[] = "fp.userId IN ({$cfFilterPlaceholders})";
                $params = array_merge($params, $myCloseFriendIds);
            } else {
                return ResponseFactory::json(['data' => []], 200, $response);
            }
        }

        $whereSql = implode(' AND ', $whereConditions);

        $sql = "SELECT fp.*, u.id AS userId, u.displayName, u.image
                FROM FeedPosts fp
                INNER JOIN `User` u ON u.id = fp.userId
                WHERE {$whereSql}
                ORDER BY fp.createdAt DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        // Enrich with canEdit and isCloseFriend
        $myCloseFriendSet = array_flip($myCloseFriendIds);
        $enriched = array_map(function ($post) use ($user, $myCloseFriendSet) {
            $post['canEdit'] = $post['userId'] === $user->id;
            $post['user'] = [
                'id' => $post['userId'],
                'displayName' => $post['displayName'],
                'image' => $post['image'],
                'isCloseFriend' => isset($myCloseFriendSet[$post['userId']]),
            ];
            return $post;
        }, $posts);

        return ResponseFactory::json(['data' => $enriched], 200, $response);
    }

    /**
     * GET /home/birthdays
     * All users with birthdays, filtered by visibility.
     */
    public function birthdays(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $cfStmt = $this->pdo->prepare(
            "SELECT userId FROM CloseFriend WHERE friendId = ?"
        );
        $cfStmt->execute([$user->id]);
        $visibleToMe = array_column($cfStmt->fetchAll(), 'userId');

        $stmt = $this->pdo->prepare(
            "SELECT id, displayName, birthday, birthdayVisibility FROM `User` WHERE birthday IS NOT NULL"
        );
        $stmt->execute();
        $allUsers = $stmt->fetchAll();

        $birthdays = array_filter($allUsers, function ($u) use ($user, $visibleToMe) {
            if ($u['id'] === $user->id) {
                return true;
            }
            $visibility = (int) ($u['birthdayVisibility'] ?? 0);
            if ($visibility === 1) {
                return true;
            }
            if ($visibility === 2 && in_array($u['id'], $visibleToMe, true)) {
                return true;
            }
            return false;
        });

        $myCfStmt = $this->pdo->prepare(
            "SELECT friendId FROM CloseFriend WHERE userId = ?"
        );
        $myCfStmt->execute([$user->id]);
        $myCloseFriendIds = array_column($myCfStmt->fetchAll(), 'friendId');

        $result = array_map(function ($u) use ($myCloseFriendIds) {
            return [
                'id' => $u['id'],
                'displayName' => $u['displayName'],
                'birthday' => $u['birthday'],
                'isCloseFriend' => in_array($u['id'], $myCloseFriendIds, true),
            ];
        }, array_values($birthdays));

        return ResponseFactory::json(['data' => $result], 200, $response);
    }
}
