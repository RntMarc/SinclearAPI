<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\UserPreferenceRepository;

final readonly class UserPreferenceService
{
    private const array ALLOWED_FIELDS = [
        'language', 'theme', 'primaryColor', 'timezone',
        'emailVisibility', 'birthdayVisibility', 'syncAvatarFromDiscord', 'onboardingCompleted',
        'discordVisibility', 'fluxerVisibility', 'matrixVisibility', 'signalVisibility', 'whatsappVisibility',
        'unsplashVisibility', 'instagramVisibility', 'mastodonVisibility', 'pixelfedVisibility',
        'blueskyVisibility', 'youtubeVisibility', 'twitchVisibility',
    ];

    private const array VISIBILITY_FIELDS = [
        'emailVisibility', 'birthdayVisibility',
        'discordVisibility', 'fluxerVisibility', 'matrixVisibility', 'signalVisibility', 'whatsappVisibility',
        'unsplashVisibility', 'instagramVisibility', 'mastodonVisibility', 'pixelfedVisibility',
        'blueskyVisibility', 'youtubeVisibility', 'twitchVisibility',
    ];

    public function __construct(
        private UserPreferenceRepository $repo,
    ) {}

    /** @return array<string, mixed> */
    public function getAll(string $userId): array
    {
        $prefs = $this->repo->findByUserId($userId);
        if ($prefs === null) {
            $this->repo->createDefaults($userId);
            $prefs = $this->repo->findByUserId($userId);
        }
        return $prefs;
    }

    /** @param array<string, mixed> $data */
    public function update(string $userId, array $data): array
    {
        $filtered = [];
        foreach ($data as $field => $value) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new \InvalidArgumentException("invalid_field: $field");
            }

            if (in_array($field, self::VISIBILITY_FIELDS, true)) {
                $intValue = is_numeric($value) ? (int) $value : -1;
                if (!in_array($intValue, [0, 1, 2], true)) {
                    throw new \InvalidArgumentException("invalid_visibility_value: $field");
                }
                $filtered[$field] = $intValue;
            } elseif ($field === 'syncAvatarFromDiscord' || $field === 'onboardingCompleted') {
                $filtered[$field] = (int) (bool) $value;
            } else {
                $filtered[$field] = $value;
            }
        }

        if ($filtered !== []) {
            $this->repo->upsert($userId, $filtered);
        }

        return $this->getAll($userId);
    }

    public function getSyncAvatarFromDiscord(string $userId): bool
    {
        $prefs = $this->getAll($userId);
        return (bool) ($prefs['syncAvatarFromDiscord'] ?? 1);
    }
}
