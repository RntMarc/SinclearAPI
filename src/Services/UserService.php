<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\UserPolicy;

final readonly class UserService
{
    private const array SOCIAL_FIELDS = [
        'unsplashHandle' => 'unsplashVisibility',
        'instagramHandle' => 'instagramVisibility',
        'mastodonHandle' => 'mastodonVisibility',
        'pixelfedHandle' => 'pixelfedVisibility',
        'blueskyHandle' => 'blueskyVisibility',
        'youtubeHandle' => 'youtubeVisibility',
        'twitchHandle' => 'twitchVisibility',
    ];

    private const array CONTACT_FIELDS = [
        'discordHandle' => 'discordVisibility',
        'fluxerHandle' => 'fluxerVisibility',
        'signalNumber' => 'signalVisibility',
        'whatsappNumber' => 'whatsappVisibility',
    ];

    public function __construct(
        private UserRepository $userRepo,
        private ContactInfoRepository $contactInfoRepo,
        private SocialInfoRepository $socialInfoRepo,
        private UserPolicy $policy,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listAll(AuthenticatedUser $requester): array
    {
        $users = $this->userRepo->findAll();
        return array_map(
            fn(array $user) => $this->formatUserBaseFiltered($user, $requester),
            $users,
        );
    }

    /** @return array<string, mixed>|null */
    public function getUser(string $id): ?array
    {
        return $this->userRepo->findById($id);
    }

    /** @return array<string, mixed>|null */
    public function getContactInfo(string $userId): ?array
    {
        return $this->contactInfoRepo->findByUserId($userId);
    }

    /** @return array<string, mixed>|null */
    public function getSocialInfo(string $userId): ?array
    {
        return $this->socialInfoRepo->findByUserId($userId);
    }

    /** @param array<string, mixed> $user */
    public function formatUserBase(array $user): array
    {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'emailVisibility' => (int) $user['emailVisibility'],
            'displayName' => $user['displayName'],
            'image' => $user['image'],
            'discordId' => $user['discordId'],
            'isAdmin' => (bool) $user['isAdmin'],
            'createdAt' => $user['createdAt'],
            'onboardingCompleted' => (bool) $user['onboardingCompleted'],
            'birthday' => $user['birthday'],
            'birthdayVisibility' => (int) $user['birthdayVisibility'],
        ];
    }

    /** @param array<string, mixed> $user */
    public function formatUserBaseFiltered(array $user, AuthenticatedUser $requester): array
    {
        $result = [
            'id' => $user['id'],
            'displayName' => $user['displayName'],
            'image' => $user['image'],
            'discordId' => $user['discordId'],
            'isAdmin' => (bool) $user['isAdmin'],
            'createdAt' => $user['createdAt'],
            'onboardingCompleted' => (bool) $user['onboardingCompleted'],
        ];

        if ($this->policy->canView($requester, (string) $user['id'], (int) $user['emailVisibility'])) {
            $result['email'] = $user['email'];
        }

        if ($this->policy->canView($requester, (string) $user['id'], (int) $user['birthdayVisibility'])) {
            $result['birthday'] = $user['birthday'];
        }

        return $result;
    }

    /** @param array<string, mixed> $social */
    public function formatSocialInfo(array $social): array
    {
        $result = [];
        foreach (self::SOCIAL_FIELDS as $handle => $visibility) {
            $result[$handle] = $social[$handle] ?? null;
            $result[$visibility] = (int) ($social[$visibility] ?? 1);
        }
        return $result;
    }

    /** @param array<string, mixed>|null $social */
    public function formatSocialInfoFiltered(?array $social, AuthenticatedUser $requester, string $targetUserId): array
    {
        if ($social === null) {
            return [];
        }

        $result = [];
        foreach (self::SOCIAL_FIELDS as $handle => $visibility) {
            $visibilityLevel = (int) ($social[$visibility] ?? 1);
            if ($this->policy->canView($requester, $targetUserId, $visibilityLevel)) {
                $result[$handle] = $social[$handle];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $contact */
    public function formatContactInfo(array $contact): array
    {
        $result = [];
        foreach (self::CONTACT_FIELDS as $field => $visibility) {
            $result[$field] = $contact[$field] ?? null;
            $result[$visibility] = (int) ($contact[$visibility] ?? 1);
        }

        $result['matrixUser'] = $contact['matrixUser'] ?? null;
        $result['matrixHomeserver'] = $contact['matrixHomeserver'] ?? null;
        $result['matrixVisibility'] = (int) ($contact['matrixVisibility'] ?? 1);

        return $result;
    }

    /** @param array<string, mixed>|null $contact */
    public function formatContactInfoFiltered(?array $contact, AuthenticatedUser $requester, string $targetUserId): array
    {
        if ($contact === null) {
            return [];
        }

        $result = [];
        foreach (self::CONTACT_FIELDS as $field => $visibility) {
            $visibilityLevel = (int) ($contact[$visibility] ?? 1);
            if ($this->policy->canView($requester, $targetUserId, $visibilityLevel)) {
                $result[$field] = $contact[$field];
            }
        }

        $matrixVisibility = (int) ($contact['matrixVisibility'] ?? 1);
        if ($this->policy->canView($requester, $targetUserId, $matrixVisibility)) {
            $result['matrixUser'] = $contact['matrixUser'];
            $result['matrixHomeserver'] = $contact['matrixHomeserver'];
        }

        return $result;
    }
}
