<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\CloseFriendRepository;
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
        'blueskyHandle' => 'blueskyVisibility',
        'youtubeHandle' => 'youtubeVisibility',
        'twitchHandle' => 'twitchVisibility',
    ];

    private const array FEDIVERSE_FIELDS = [
        'mastodonHandle' => 'mastodonVisibility',
        'pixelfedHandle' => 'pixelfedVisibility',
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
        private UserPreferenceService $preferenceService,
        private UserPolicy $policy,
        private CloseFriendRepository $closeFriendRepo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listAll(AuthenticatedUser $requester): array
    {
        $users = $this->userRepo->findAll();
        $closeFriendIds = $this->closeFriendRepo->listFriendIdsFor($requester->id);
        return array_map(
            fn(array $user) => $this->formatUserBaseFiltered($user, $requester, $closeFriendIds),
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

    /**
     * Liste der vom anfragenden Nutzer markierten engen Freunde (ausgehende
     * Richtung). Jeder Eintrag entspricht formatUserBaseFiltered (mit
     * isCloseFriend = true).
     *
     * @return list<array<string, mixed>>
     */
    public function listCloseFriends(AuthenticatedUser $requester): array
    {
        $friendIds = $this->closeFriendRepo->listFriendIdsFor($requester->id);

        $friends = [];
        foreach ($friendIds as $friendId) {
            $user = $this->userRepo->findById($friendId);
            if ($user !== null) {
                $friends[] = $this->formatUserBaseFiltered($user, $requester, $friendIds);
            }
        }

        return $friends;
    }

    public function addCloseFriend(string $userId, string $friendId): void
    {
        $this->closeFriendRepo->add($userId, $friendId);
    }

    public function removeCloseFriend(string $userId, string $friendId): void
    {
        $this->closeFriendRepo->remove($userId, $friendId);
    }

    /** @param array<string, mixed> $user */
    public function formatUserBase(array $user): array
    {
        $prefs = $this->preferenceService->getAll($user['id']);

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'emailVisibility' => (int) ($prefs['emailVisibility'] ?? 1),
            'displayName' => $user['displayName'],
            'image' => $user['image'],
            'discordId' => $user['discordId'],
            'discordAvatarHash' => $user['discordAvatarHash'],
            'syncAvatarFromDiscord' => (bool) ($prefs['syncAvatarFromDiscord'] ?? 1),
            'isAdmin' => (bool) $user['isAdmin'],
            'createdAt' => $user['createdAt'],
            'onboardingCompleted' => (bool) ($prefs['onboardingCompleted'] ?? 0),
            'birthday' => $user['birthday'],
            'birthdayVisibility' => (int) ($prefs['birthdayVisibility'] ?? 1),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string>|null $closeFriendIds Vorab geladenes Friend-ID-Set des
     *                                         Requesters (ausgehende Richtung).
     *                                         Wird bei null intern geladen.
     */
    public function formatUserBaseFiltered(array $user, AuthenticatedUser $requester, ?array $closeFriendIds = null): array
    {
        $prefs = $this->preferenceService->getAll($user['id']);
        $closeFriendIds ??= $this->closeFriendRepo->listFriendIdsFor($requester->id);

        $result = [
            'id' => $user['id'],
            'displayName' => $user['displayName'],
            'image' => $user['image'],
            'discordId' => $user['discordId'],
            'discordAvatarHash' => $user['discordAvatarHash'],
            'isAdmin' => (bool) $user['isAdmin'],
            'createdAt' => $user['createdAt'],
            'onboardingCompleted' => (bool) ($prefs['onboardingCompleted'] ?? 0),
            // Ausgehende Richtung: hat der anfragende Nutzer diesen Nutzer
            // als engen Freund markiert (CloseFriend { userId: requester, friendId: target }).
            'isCloseFriend' => in_array((string) $user['id'], $closeFriendIds, true),
        ];

        if ($this->policy->canView($requester, (string) $user['id'], (int) ($prefs['emailVisibility'] ?? 1))) {
            $result['email'] = $user['email'];
        }

        if ($this->policy->canView($requester, (string) $user['id'], (int) ($prefs['birthdayVisibility'] ?? 1))) {
            $result['birthday'] = $user['birthday'];
        }

        return $result;
    }

    /** @param array<string, mixed> $social */
    public function formatSocialInfo(string $userId, array $social): array
    {
        $prefs = $this->preferenceService->getAll($userId);
        $result = [];
        foreach (self::SOCIAL_FIELDS as $handle => $visibility) {
            $result[$handle] = $social[$handle] ?? null;
            $result[$visibility] = (int) ($prefs[$visibility] ?? 1);
        }

        foreach (self::FEDIVERSE_FIELDS as $handle => $visibility) {
            $combined = $social[$handle] ?? null;
            $result[$visibility] = (int) ($prefs[$visibility] ?? 1);
            if ($combined !== null && str_contains($combined, '@')) {
                $parts = explode('@', $combined, 2);
                $result[str_replace('Handle', 'User', lcfirst(ucfirst($handle)))] = $parts[0];
                $result[str_replace('Handle', 'Server', lcfirst(ucfirst($handle)))] = $parts[1];
            } else {
                $fieldPrefix = match ($handle) {
                    'mastodonHandle' => 'mastodon',
                    'pixelfedHandle' => 'pixelfed',
                };
                $result[$fieldPrefix . 'User'] = null;
                $result[$fieldPrefix . 'Server'] = null;
            }
        }

        return $result;
    }

    /** @param array<string, mixed>|null $social */
    public function formatSocialInfoFiltered(?array $social, AuthenticatedUser $requester, string $targetUserId): array
    {
        if ($social === null) {
            return [];
        }

        $prefs = $this->preferenceService->getAll($targetUserId);
        $result = [];
        foreach (self::SOCIAL_FIELDS as $handle => $visibility) {
            $visibilityLevel = (int) ($prefs[$visibility] ?? 1);
            if ($this->policy->canView($requester, $targetUserId, $visibilityLevel)) {
                $result[$handle] = $social[$handle];
            }
        }

        foreach (self::FEDIVERSE_FIELDS as $handle => $visibility) {
            $visibilityLevel = (int) ($prefs[$visibility] ?? 1);
            if ($this->policy->canView($requester, $targetUserId, $visibilityLevel)) {
                $combined = $social[$handle] ?? null;
                $fieldPrefix = match ($handle) {
                    'mastodonHandle' => 'mastodon',
                    'pixelfedHandle' => 'pixelfed',
                };
                if ($combined !== null && str_contains($combined, '@')) {
                    $parts = explode('@', $combined, 2);
                    $result[$fieldPrefix . 'User'] = $parts[0];
                    $result[$fieldPrefix . 'Server'] = $parts[1];
                } else {
                    $result[$fieldPrefix . 'User'] = null;
                    $result[$fieldPrefix . 'Server'] = null;
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $contact */
    public function formatContactInfo(string $userId, array $contact): array
    {
        $prefs = $this->preferenceService->getAll($userId);
        $result = [];
        foreach (self::CONTACT_FIELDS as $field => $visibility) {
            $result[$field] = $contact[$field] ?? null;
            $result[$visibility] = (int) ($prefs[$visibility] ?? 1);
        }

        $result['matrixUser'] = $contact['matrixUser'] ?? null;
        $result['matrixHomeserver'] = $contact['matrixHomeserver'] ?? null;
        $result['matrixVisibility'] = (int) ($prefs['matrixVisibility'] ?? 1);

        return $result;
    }

    /** @param array<string, mixed>|null $contact */
    public function formatContactInfoFiltered(?array $contact, AuthenticatedUser $requester, string $targetUserId): array
    {
        if ($contact === null) {
            return [];
        }

        $prefs = $this->preferenceService->getAll($targetUserId);
        $result = [];
        foreach (self::CONTACT_FIELDS as $field => $visibility) {
            $visibilityLevel = (int) ($prefs[$visibility] ?? 1);
            if ($this->policy->canView($requester, $targetUserId, $visibilityLevel)) {
                $result[$field] = $contact[$field];
            }
        }

        $matrixVisibility = (int) ($prefs['matrixVisibility'] ?? 1);
        if ($this->policy->canView($requester, $targetUserId, $matrixVisibility)) {
            $result['matrixUser'] = $contact['matrixUser'];
            $result['matrixHomeserver'] = $contact['matrixHomeserver'];
        }

        return $result;
    }
}
