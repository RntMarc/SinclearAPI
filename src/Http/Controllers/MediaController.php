<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class MediaController
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
     * GET /media/list
     * List media items with review stats. Query param: type=game|movie|music
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $type = $request->getQueryParams()['type'] ?? null;

        if (!$type || !in_array($type, ['game', 'movie', 'music'])) {
            throw HttpException::badRequest('invalid_type');
        }

        $joinType = $type === 'music' ? 'INNER' : 'LEFT';

        $sql = "SELECT mi.id, mi.title, mi.description, mi.image, mi.type, mi.format,
                       AVG(mr.rating) AS avgRating,
                       COUNT(mr.id) AS reviewCount
                FROM MediaItem mi
                {$joinType} JOIN MediaReview mr ON mr.itemId = mi.id
                WHERE mi.type = ?
                GROUP BY mi.id
                ORDER BY mi.updatedAt DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$type]);
        $items = $stmt->fetchAll();

        // Round avgRating
        $items = array_map(function ($item) {
            $item['avgRating'] = $item['avgRating'] !== null ? round((float) $item['avgRating'], 1) : null;
            $item['reviewCount'] = (int) $item['reviewCount'];
            return $item;
        }, $items);

        return ResponseFactory::json(['data' => $items], 200, $response);
    }

    /**
     * GET /media/{id}/detail
     * Single media item with reviews. Returns item, reviews, and optionally episodes/tracks.
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $itemId = $args['id'];

        // Get item with stats
        $sql = "SELECT mi.id, mi.title, mi.description, mi.image, mi.type, mi.format, mi.releaseDate,
                       AVG(mr.rating) AS avgRating,
                       COUNT(mr.id) AS reviewCount
                FROM MediaItem mi
                LEFT JOIN MediaReview mr ON mr.itemId = mi.id
                WHERE mi.id = ?
                GROUP BY mi.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw HttpException::notFound();
        }
        $item['avgRating'] = $item['avgRating'] !== null ? round((float) $item['avgRating'], 1) : null;
        $item['reviewCount'] = (int) $item['reviewCount'];

        // Get reviews
        $revStmt = $this->pdo->prepare(
            "SELECT mr.id, mr.rating, mr.comment, mr.platform, mr.createdAt,
                    u.id AS userId, u.displayName, u.image
             FROM MediaReview mr
             INNER JOIN `User` u ON u.id = mr.userId
             WHERE mr.itemId = ?
             ORDER BY mr.createdAt DESC"
        );
        $revStmt->execute([$itemId]);
        $reviews = $revStmt->fetchAll();

        $result = ['item' => $item, 'reviews' => $reviews];

        // Episodes (for series)
        if ($item['type'] === 'movie' && ($item['format'] ?? '') === 'series') {
            $epStmt = $this->pdo->prepare(
                "SELECT se.id, se.seasonNumber, se.episodeNumber, se.title, se.releaseDate,
                        AVG(er.rating) AS avgRating,
                        COUNT(er.id) AS reviewCount,
                        (SELECT rating FROM EpisodeReview WHERE episodeId = se.id AND userId = ?) AS userRating
                 FROM SeriesEpisode se
                 LEFT JOIN EpisodeReview er ON er.episodeId = se.id
                 WHERE se.seriesId = ?
                 GROUP BY se.id
                 ORDER BY se.seasonNumber, se.episodeNumber"
            );
            $epStmt->execute([$user->id, $itemId]);
            $result['episodes'] = $epStmt->fetchAll();
        }

        // Tracks (for albums)
        if ($item['type'] === 'music' && ($item['format'] ?? '') === 'album') {
            $trackStmt = $this->pdo->prepare(
                "SELECT mi2.id, mi2.title, mi2.format, at2.trackNumber,
                        AVG(mr2.rating) AS avgRating,
                        COUNT(mr2.id) AS reviewCount
                 FROM AlbumTrack at2
                 INNER JOIN MediaItem mi2 ON mi2.id = at2.songId
                 LEFT JOIN MediaReview mr2 ON mr2.itemId = mi2.id
                 WHERE at2.albumId = ?
                 GROUP BY mi2.id, at2.trackNumber
                 ORDER BY at2.trackNumber"
            );
            $trackStmt->execute([$itemId]);
            $result['tracks'] = $trackStmt->fetchAll();
        }

        // If song, find albums containing it
        if ($item['type'] === 'music' && ($item['format'] ?? '') === 'song') {
            $albumStmt = $this->pdo->prepare(
                "SELECT mi2.id, mi2.title, mi2.image, mi2.format
                 FROM AlbumTrack at2
                 INNER JOIN MediaItem mi2 ON mi2.id = at2.albumId
                 WHERE at2.songId = ?"
            );
            $albumStmt->execute([$itemId]);
            $albums = $albumStmt->fetchAll();
            $result['albums'] = $albums;

            // Get tracks for each album
            foreach ($albums as &$album) {
                $trackStmt = $this->pdo->prepare(
                    "SELECT mi3.id, mi3.title, mi3.format, at3.trackNumber,
                            AVG(mr3.rating) AS avgRating,
                            COUNT(mr3.id) AS reviewCount
                     FROM AlbumTrack at3
                     INNER JOIN MediaItem mi3 ON mi3.id = at3.songId
                     LEFT JOIN MediaReview mr3 ON mr3.itemId = mi3.id
                     WHERE at3.albumId = ?
                     GROUP BY mi3.id, at3.trackNumber
                     ORDER BY at3.trackNumber"
                );
                $trackStmt->execute([$album['id']]);
                $album['tracks'] = $trackStmt->fetchAll();
            }
            unset($album);
        }

        return ResponseFactory::json(['data' => $result], 200, $response);
    }

    /**
     * GET /media/{id}/reviews
     * Reviews for a media item with user info.
     */
    public function reviews(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $itemId = $args['id'];

        $revStmt = $this->pdo->prepare(
            "SELECT mr.id, mr.rating, mr.comment, mr.platform, mr.createdAt,
                    u.id AS userId, u.displayName, u.image
             FROM MediaReview mr
             INNER JOIN `User` u ON u.id = mr.userId
             WHERE mr.itemId = ?
             ORDER BY mr.createdAt DESC"
        );
        $revStmt->execute([$itemId]);
        $reviews = $revStmt->fetchAll();

        return ResponseFactory::json(['data' => $reviews], 200, $response);
    }

    /**
     * POST /media/{id}/reviews
     * Create or update a review for a media item (upsert).
     */
    public function upsertReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $itemId = $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        $rating = (int) ($body['rating'] ?? 0);
        $comment = $body['comment'] ?? null;
        $platform = $body['platform'] ?? null;

        if ($rating < 1 || $rating > 5) {
            throw HttpException::badRequest('invalid_rating');
        }

        // Check for existing review
        $existStmt = $this->pdo->prepare(
            "SELECT id FROM MediaReview WHERE itemId = ? AND userId = ? LIMIT 1"
        );
        $existStmt->execute([$itemId, $user->id]);
        $existing = $existStmt->fetch();

        if ($existing) {
            $this->pdo->prepare(
                "UPDATE MediaReview SET rating = ?, comment = ?, platform = ?, createdAt = NOW() WHERE id = ?"
            )->execute([$rating, $comment, $platform, $existing['id']]);
            $reviewId = $existing['id'];
        } else {
            $reviewId = bin2hex(random_bytes(16));
            $this->pdo->prepare(
                "INSERT INTO MediaReview (id, itemId, userId, rating, comment, platform, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())"
            )->execute([$reviewId, $itemId, $user->id, $rating, $comment, $platform]);
        }

        // Update item timestamp
        $this->pdo->prepare("UPDATE MediaItem SET updatedAt = NOW() WHERE id = ?")->execute([$itemId]);

        return ResponseFactory::json(['data' => ['id' => $reviewId]], 201, $response);
    }
}
