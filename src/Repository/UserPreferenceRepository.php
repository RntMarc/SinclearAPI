<?php

namespace Sinclear\Api\Repository;

use PDO;

final readonly class UserPreferenceRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return array<string, mixed>|null */
    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, userId, language, theme, primaryColor, timezone,
                    emailVisibility, birthdayVisibility, syncAvatarFromDiscord, onboardingCompleted,
                    discordVisibility, fluxerVisibility, matrixVisibility, signalVisibility, whatsappVisibility,
                    unsplashVisibility, instagramVisibility, mastodonVisibility, pixelfedVisibility,
                    blueskyVisibility, youtubeVisibility, twitchVisibility,
                    createdAt, updatedAt
             FROM UserPreferences WHERE userId = ?'
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /** @param array<string, mixed> $data */
    public function upsert(string $userId, array $data): void
    {
        $existing = $this->findByUserId($userId);

        if ($existing === null) {
            $this->createDefaults($userId);
            $existing = $this->findByUserId($userId);
        }

        $sets = [];
        $values = [];
        foreach ($data as $field => $value) {
            $sets[] = "`$field` = ?";
            $values[] = $value;
        }
        $sets[] = 'updatedAt = NOW(3)';
        $values[] = $userId;

        $sql = 'UPDATE UserPreferences SET ' . implode(', ', $sets) . ' WHERE userId = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    /** @param string|int|bool|null $value */
    public function upsertField(string $userId, string $field, mixed $value): void
    {
        $this->upsert($userId, [$field => $value]);
    }

    public function createDefaults(string $userId): void
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO UserPreferences (id, userId, language, theme, primaryColor, timezone,
                emailVisibility, birthdayVisibility, syncAvatarFromDiscord, onboardingCompleted,
                discordVisibility, fluxerVisibility, matrixVisibility, signalVisibility, whatsappVisibility,
                unsplashVisibility, instagramVisibility, mastodonVisibility, pixelfedVisibility,
                blueskyVisibility, youtubeVisibility, twitchVisibility,
                createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?,
                1, 1, 1, 0,
                1, 1, 1, 1, 1,
                1, 1, 1, 1,
                1, 1, 1,
                NOW(3), NOW(3))'
        );
        $stmt->execute([$id, $userId, 'de', 'light', '#6366f1', 'Europe/Berlin']);
    }
}
