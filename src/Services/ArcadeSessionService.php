<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\ArcadeSessionRepository;

final readonly class ArcadeSessionService
{
    public function __construct(
        private Settings $settings,
        private ArcadeSessionRepository $repo,
    ) {}

    /** @return array{id: string, token: string, expiresAt: string} */
    public function createSession(string $userId, string $gameId): array
    {
        $token = self::generateToken();
        $expiresAt = $this->nextExpiry();

        $id = $this->repo->create(
            userId: $userId,
            gameId: $gameId,
            tokenHash: self::hashToken($token),
            expiresAt: $expiresAt,
        );

        return [
            'id' => $id,
            'token' => $token,
            'expiresAt' => self::formatTimestamp($expiresAt),
        ];
    }

    /** @return array<string, mixed>|null */
    public function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return $this->repo->findValidByTokenHash(self::hashToken($token));
    }

    /**
     * Rotiert das Token: das alte wird entwertet, ein neues mit frischer
     * Laufzeit fuer denselben Nutzer und dasselbe Spiel ausgestellt.
     *
     * @param array<string, mixed> $session
     * @return array{id: string, token: string, expiresAt: string}
     */
    public function renew(array $session): array
    {
        $this->repo->revoke((string) $session['id'], new DateTimeImmutable());

        return $this->createSession(
            (string) $session['userId'],
            (string) $session['gameId'],
        );
    }

    public function revokeAll(string $userId, string $gameId): int
    {
        return $this->repo->revokeAllForUserAndGame(
            $userId,
            $gameId,
            new DateTimeImmutable(),
        );
    }

    public function getTtl(): int
    {
        $ttl = (int) ($this->settings->arcade['session_ttl'] ?? 900);

        return $ttl > 0 ? $ttl : 900;
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function nextExpiry(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->modify('+' . $this->getTtl() . ' seconds');
    }

    private static function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
