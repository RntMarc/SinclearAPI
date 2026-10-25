<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sinclear\Api\Repository\GameAccountRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\Games\GameRegistry;

final readonly class GameAccountService
{
    public function __construct(
        private GameAccountRepository $repo,
        private GameRegistry $registry,
        private UserRepository $userRepo,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listOwn(string $userId): array
    {
        return array_map($this->formatAccount(...), $this->repo->listByUserId($userId));
    }

    /**
     * Oeffentliche Liste der verknuepften Accounts eines Beyond-Nutzers.
     *
     * @return list<array<string, mixed>>|null null, wenn der Nutzer nicht existiert
     */
    public function listForUser(string $userId): ?array
    {
        if ($this->userRepo->findById($userId) === null) {
            return null;
        }

        return array_map($this->formatAccount(...), $this->repo->listByUserId($userId));
    }

    /**
     * Verknuepft einen Spiele-Account und loest dabei das Profil auf.
     *
     * @return array<string, mixed>
     */
    public function link(string $userId, string $game, string $accountId): array
    {
        $integration = $this->registry->get($game);
        $accountId = $this->validateAccountId($accountId);

        $profile = $integration->resolveProfile($accountId);

        $id = $this->repo->upsert(
            userId: $userId,
            game: $game,
            accountId: $accountId,
            displayName: $profile['displayName'],
            avatarUrl: $profile['avatarUrl'],
            profile: $profile['profile'],
        );

        $account = $this->repo->findByUserIdAndGame($userId, $game);
        if ($account === null || $account['id'] !== $id) {
            throw new \RuntimeException('game_account_persist_failed');
        }

        return $this->formatAccount($account);
    }

    public function unlink(string $userId, string $game): bool
    {
        $this->registry->get($game);

        return $this->repo->deleteByUserIdAndGame($userId, $game);
    }

    /** @return array<string, mixed>|null */
    public function findAccount(string $game, string $userId): ?array
    {
        $this->registry->get($game);

        return $this->repo->findByUserIdAndGame($userId, $game);
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function formatAccount(array $account): array
    {
        return [
            'id' => $account['id'],
            'userId' => $account['userId'],
            'game' => $account['game'],
            'accountId' => $account['accountId'],
            'displayName' => $account['displayName'],
            'avatarUrl' => $account['avatarUrl'],
            'lastSyncedAt' => self::formatTimestamp($account['lastSyncedAt'] ?? null),
            'createdAt' => self::formatTimestamp($account['createdAt'] ?? null),
            'updatedAt' => self::formatTimestamp($account['updatedAt'] ?? null),
        ];
    }

    private function validateAccountId(string $accountId): string
    {
        $accountId = trim($accountId);

        if ($accountId === '' || !ctype_digit($accountId) || strlen($accountId) > 10) {
            throw new InvalidArgumentException('invalid_account_id');
        }

        $value = (int) $accountId;
        if ($value < 1 || $value > 4294967295) {
            throw new InvalidArgumentException('invalid_account_id');
        }

        return (string) $value;
    }

    private static function formatTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
