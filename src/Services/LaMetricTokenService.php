<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Repository\LaMetricTokenRepository;

final readonly class LaMetricTokenService
{
    public const int TOKEN_TTL_DAYS = 365;
    public const int TOKEN_LENGTH = 32;
    private const int LAST_USED_THROTTLE_SECONDS = 3600;
    private const string DEFAULT_LABEL = 'LaMetric Time';

    public function __construct(
        private LaMetricTokenRepository $repo,
    ) {}

    /**
     * Gibt das gespeicherte Token des Nutzers zurück (inkl. Klartext-Token)
     * oder null, wenn noch keines erzeugt wurde.
     *
     * @return array<string, mixed>|null
     */
    public function getToken(string $userId): ?array
    {
        $row = $this->repo->findByUserId($userId);
        return $row === null ? null : $this->format($row);
    }

    /**
     * Erzeugt ein neues Token bzw. ersetzt das vorhandene (genau eines pro Nutzer).
     *
     * @return array<string, mixed>
     */
    public function saveToken(string $userId, ?string $label): array
    {
        $label = $label !== null ? trim($label) : '';
        if ($label === '') {
            $label = self::DEFAULT_LABEL;
        }
        if (mb_strlen($label) > 100) {
            $label = mb_substr($label, 0, 100);
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        $expiresAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->modify('+' . self::TOKEN_TTL_DAYS . ' days');

        $row = $this->repo->upsert([
            'userId' => $userId,
            'label' => $label,
            'token' => $token,
            'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return $this->format($row);
    }

    public function deleteToken(string $userId): bool
    {
        return $this->repo->deleteByUserId($userId);
    }

    /**
     * Validiert einen LaMetric-Token und gibt die User-ID zurück oder null.
     */
    public function validateToken(string $token): ?string
    {
        if ($token === '' || preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
            return null;
        }

        $token = strtolower($token);
        $tokenData = $this->repo->findByToken($token);

        if ($tokenData === null) {
            return null;
        }

        $expiresAt = new DateTimeImmutable($tokenData['expiresAt'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($now > $expiresAt) {
            return null;
        }

        $throttleLimit = $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds');
        $this->repo->touchLastUsed($tokenData['id'], $throttleLimit->format('Y-m-d H:i:s'));

        return (string) $tokenData['userId'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        return [
            'id' => $row['id'],
            'label' => $row['label'],
            'token' => $row['token'],
            'expiresAt' => $row['expiresAt'],
            'lastUsedAt' => $row['lastUsedAt'],
            'createdAt' => $row['createdAt'],
        ];
    }
}
