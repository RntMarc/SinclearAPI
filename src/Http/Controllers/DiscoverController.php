<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class DiscoverController
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
     * GET /discover/list
     * List places with review stats. Query param: category=leisure|gastronomy
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $category = $request->getQueryParams()['category'] ?? null;

        $sql = "SELECT dp.id, dp.name, dp.address, dp.openingHours, dp.latitude, dp.longitude,
                       AVG(dr.rating) AS avgRating,
                       COUNT(dr.id) AS reviewCount
                FROM DiscoverPlace dp
                LEFT JOIN DiscoverReview dr ON dr.placeId = dp.id";

        $params = [];
        if ($category && in_array($category, ['leisure', 'gastronomy'])) {
            $sql .= " WHERE dp.category = ?";
            $params[] = $category;
        }

        $sql .= " GROUP BY dp.id ORDER BY dp.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $places = $stmt->fetchAll();

        $places = array_map(function ($p) {
            $p['avgRating'] = $p['avgRating'] !== null ? round((float) $p['avgRating'], 1) : null;
            $p['reviewCount'] = (int) $p['reviewCount'];
            return $p;
        }, $places);

        return ResponseFactory::json(['data' => $places], 200, $response);
    }

    /**
     * GET /discover/random
     * Random places with review stats (limit 11).
     */
    public function random(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $sql = "SELECT dp.id, dp.name, dp.address, dp.category,
                       AVG(dr.rating) AS avgRating,
                       COUNT(dr.id) AS reviewCount
                FROM DiscoverPlace dp
                LEFT JOIN DiscoverReview dr ON dr.placeId = dp.id
                GROUP BY dp.id
                ORDER BY RAND()
                LIMIT 11";

        $stmt = $this->pdo->query($sql);
        $places = $stmt->fetchAll();

        $places = array_map(function ($p) {
            $p['avgRating'] = $p['avgRating'] !== null ? round((float) $p['avgRating'], 1) : null;
            $p['reviewCount'] = (int) $p['reviewCount'];
            return $p;
        }, $places);

        return ResponseFactory::json(['data' => $places], 200, $response);
    }

    /**
     * GET /discover/map
     * All places with coordinates for map display.
     */
    public function map(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $stmt = $this->pdo->query(
            "SELECT id, name, address, latitude, longitude, category FROM DiscoverPlace"
        );
        $places = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $places], 200, $response);
    }

    /**
     * GET /discover/bookmarked
     * User's bookmarked places.
     */
    public function bookmarked(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $stmt = $this->pdo->prepare(
            "SELECT dp.id, dp.name, dp.category, dp.address
             FROM DiscoverBookmark db
             INNER JOIN DiscoverPlace dp ON dp.id = db.placeId
             WHERE db.userId = ?"
        );
        $stmt->execute([$user->id]);
        $places = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $places], 200, $response);
    }

    /**
     * GET /discover/{id}/detail
     * Single place with reviews and gastronomy data.
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $placeId = $args['id'];

        // Get place
        $stmt = $this->pdo->prepare("SELECT * FROM DiscoverPlace WHERE id = ? LIMIT 1");
        $stmt->execute([$placeId]);
        $place = $stmt->fetch();
        if (!$place) {
            throw HttpException::notFound();
        }

        // Get gastronomy data if applicable
        $gastroStmt = $this->pdo->prepare(
            "SELECT * FROM DiscoverGastronomy WHERE placeId = ? LIMIT 1"
        );
        $gastroStmt->execute([$placeId]);
        $gastronomy = $gastroStmt->fetch();

        // Get reviews
        $revStmt = $this->pdo->prepare(
            "SELECT dr.id, dr.rating, dr.comment, dr.createdAt, dr.userId,
                    u.displayName AS userDisplayName, u.image AS userImage
             FROM DiscoverReview dr
             INNER JOIN `User` u ON u.id = dr.userId
             WHERE dr.placeId = ?
             ORDER BY dr.createdAt DESC"
        );
        $revStmt->execute([$placeId]);
        $reviews = $revStmt->fetchAll();

        // Check bookmark
        $bmStmt = $this->pdo->prepare(
            "SELECT 1 FROM DiscoverBookmark WHERE placeId = ? AND userId = ? LIMIT 1"
        );
        $bmStmt->execute([$placeId, $user->id]);
        $isBookmarked = (bool) $bmStmt->fetch();

        return ResponseFactory::json([
            'data' => [
                ...$place,
                'details' => [
                    ...($gastronomy ?: []),
                ],
                'reviews' => $reviews,
                'isBookmarked' => $isBookmarked,
            ],
        ], 200, $response);
    }

    /**
     * GET /discover/places-search
     * Search places with various filters: category, q, mode, lat, lon, radius, random, locationName
     */
    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $params = $request->getQueryParams();

        $category = $params['category'] ?? null;
        $q = $params['q'] ?? null;
        $mode = $params['mode'] ?? null;
        $lat = $params['lat'] ?? null;
        $lon = $params['lon'] ?? null;
        $radius = $params['radius'] ?? null;
        $random = $params['random'] ?? null;
        $locationName = $params['locationName'] ?? null;

        $sql = "SELECT dp.id, dp.name, dp.address, dp.category, dp.latitude, dp.longitude,
                       dp.openingHours,
                       AVG(dr.rating) AS avgRating,
                       COUNT(dr.id) AS reviewCount
                FROM DiscoverPlace dp
                LEFT JOIN DiscoverReview dr ON dr.placeId = dp.id";

        $conditions = [];
        $params = [];

        if ($category) {
            $conditions[] = "dp.category = ?";
            $params[] = $category;
        }

        if ($q) {
            $conditions[] = "(dp.name LIKE ? OR dp.address LIKE ?)";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }

        if ($mode === 'around' && $lat && $lon && $radius) {
            $r = (float) $radius;
            $latitude = (float) $lat;
            $longitude = (float) $lon;
            $conditions[] = "(6371 * acos(cos(radians(?)) * cos(radians(dp.latitude)) * cos(radians(dp.longitude) - radians(?)) + sin(radians(?)) * sin(radians(dp.latitude)))) <= ?";
            $params[] = $latitude;
            $params[] = $longitude;
            $params[] = $latitude;
            $params[] = $r;
        } elseif ($mode === 'in' && $lat && $lon) {
            $latitude = (float) $lat;
            $longitude = (float) $lon;
            $conditions[] = "(6371 * acos(cos(radians(?)) * cos(radians(dp.latitude)) * cos(radians(dp.longitude) - radians(?)) + sin(radians(?)) * sin(radians(dp.latitude)))) <= 15";
            $params[] = $latitude;
            $params[] = $longitude;
            $params[] = $latitude;

            if ($locationName) {
                $cityPart = explode(',', $locationName)[0];
                $cityPart = trim($cityPart);
                $conditions[] = "dp.address LIKE ?";
                $params[] = "%{$cityPart}%";
            }
        }

        if ($conditions !== []) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " GROUP BY dp.id";

        if ($random) {
            $sql .= " ORDER BY RAND() LIMIT " . (int) $random;
        } else {
            $sql .= " ORDER BY dp.name ASC";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $places = $stmt->fetchAll();

        $places = array_map(function ($p) {
            $p['avgRating'] = $p['avgRating'] !== null ? round((float) $p['avgRating'], 1) : null;
            $p['reviewCount'] = (int) $p['reviewCount'];
            return $p;
        }, $places);

        return ResponseFactory::json(['data' => $places], 200, $response);
    }
}
