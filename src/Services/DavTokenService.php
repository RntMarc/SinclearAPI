<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Repository\DavTokenRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class DavTokenService
{
    public const int TOKEN_TTL_DAYS = 365;
    public const int MAX_TOKENS_PER_USER = 5;
    public const int TOKEN_LENGTH = 40;
    private const int LAST_USED_THROTTLE_SECONDS = 3600;

    public function __construct(
        private DavTokenRepository $repo,
        private UserRepository $userRepo,
    ) {}

    /** @return array<string, mixed> */
    public function createToken(string $userId, string $label): array
    {
        $count = $this->repo->countByUser($userId);
        if ($count >= self::MAX_TOKENS_PER_USER) {
            throw new \RuntimeException('token_limit_reached');
        }

        $rawToken = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $keyHash = hash('sha256', $rawToken);

        $expiresAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->modify('+' . self::TOKEN_TTL_DAYS . ' days');

        $tokenData = $this->repo->create([
            'userId' => $userId,
            'label' => $label,
            'keyHash' => $keyHash,
            'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return [
            'id' => $tokenData['id'],
            'label' => $tokenData['label'],
            'token' => $rawToken,
            'expiresAt' => $tokenData['expiresAt'],
            'createdAt' => $tokenData['createdAt'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Validiert Basic-Auth-Credentials (E-Mail + DAV-Token) für den
     * CalDAV-/CardDAV-Endpunkt. Gibt die User-ID zurück oder null.
     */
    public function validateToken(string $email, string $token): ?string
    {
        if ($token === '' || $email === '') {
            return null;
        }

        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            return null;
        }

        $keyHash = hash('sha256', $token);
        $tokenData = $this->repo->findByHash($keyHash);

        if ($tokenData === null || $tokenData['userId'] !== $user['id']) {
            return null;
        }

        $expiresAt = new DateTimeImmutable($tokenData['expiresAt'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($now > $expiresAt) {
            return null;
        }

        $throttleLimit = $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds');
        $this->repo->touchLastUsed($tokenData['id'], $throttleLimit->format('Y-m-d H:i:s'));

        return (string) $user['id'];
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function listTokens(string $userId, int $page, int $limit): array
    {
        return $this->repo->listByUser($userId, $page, $limit);
    }

    public function revokeToken(string $id, string $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }
}
