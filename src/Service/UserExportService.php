<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Sinclear\Api\Dto\UserDto;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * GDPR data export for a user.
 */
final class UserExportService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(AuthenticatedUser $user, string $targetUserId): array
    {
        if ($targetUserId !== $user->id && !$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $userStmt = $this->pdo->prepare('SELECT * FROM `User` WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $targetUserId]);
        $userData = $userStmt->fetch();
        if ($userData === false) {
            throw HttpException::notFound();
        }

        $tables = [
            'UserPreferences' => 'userId',
            'ContactInfo' => 'userId',
            'SocialInfo' => 'userId',
            'CloseFriend' => 'userId',
            'Notification' => 'userId',
            'PushSubscription' => 'userId',
        ];

        $export = ['user' => UserDto::fromRow($userData), 'related' => []];

        foreach ($tables as $table => $column) {
            $stmt = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = :userId");
            $stmt->execute(['userId' => $targetUserId]);
            $export['related'][$table] = $stmt->fetchAll();
        }

        // Discover reviews with place info
        $drStmt = $this->pdo->prepare(
            "SELECT dr.id, dr.rating, dr.comment, dr.createdAt,
                    dp.name AS placeName, dp.osmId, dp.osmType, dp.category
             FROM DiscoverReview dr
             INNER JOIN DiscoverPlace dp ON dp.id = dr.placeId
             WHERE dr.userId = :userId"
        );
        $drStmt->execute(['userId' => $targetUserId]);
        $export['discoverReviews'] = $drStmt->fetchAll();

        // Media reviews with item info
        $mrStmt = $this->pdo->prepare(
            "SELECT mr.id, mr.rating, mr.comment, mr.platform, mr.createdAt,
                    mi.title AS itemTitle, mi.type AS itemType, mi.format AS itemFormat, mi.externalId AS itemExternalId
             FROM MediaReview mr
             INNER JOIN MediaItem mi ON mi.id = mr.itemId
             WHERE mr.userId = :userId"
        );
        $mrStmt->execute(['userId' => $targetUserId]);
        $export['mediaReviews'] = $mrStmt->fetchAll();

        // Episode reviews
        $erStmt = $this->pdo->prepare(
            "SELECT er.id, er.rating, er.createdAt,
                    se.title AS episodeTitle, se.seasonNumber, se.episodeNumber, se.externalId AS episodeExternalId,
                    mi.title AS seriesTitle, mi.externalId AS seriesExternalId
             FROM EpisodeReview er
             INNER JOIN SeriesEpisode se ON se.id = er.episodeId
             INNER JOIN MediaItem mi ON mi.id = se.seriesId
             WHERE er.userId = :userId"
        );
        $erStmt->execute(['userId' => $targetUserId]);
        $export['episodeReviews'] = $erStmt->fetchAll();

        return $export;
    }
}
