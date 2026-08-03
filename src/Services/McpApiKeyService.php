<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\McpApiKeyRepository;

final readonly class McpApiKeyService
{
    public const int KEY_TTL_DAYS = 90;
    public const int MAX_KEYS_PER_USER = 3;
    public const int KEY_LENGTH = 40;

    public function __construct(
        private McpApiKeyRepository $repo,
    ) {}

    public function createKey(string $userId, string $label): array
    {
        $count = $this->repo->countByUser($userId);
        if ($count >= self::MAX_KEYS_PER_USER) {
            throw new \RuntimeException('key_limit_reached');
        }

        $rawKey = bin2hex(random_bytes(self::KEY_LENGTH));
        $keyHash = hash('sha256', $rawKey);

        $expiresAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $expiresAt->modify('+' . self::KEY_TTL_DAYS . ' days');

        $keyData = $this->repo->create([
            'userId' => $userId,
            'label' => $label,
            'keyHash' => $keyHash,
            'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return [
            'id' => $keyData['id'],
            'label' => $keyData['label'],
            'key' => $rawKey,
            'expiresAt' => $keyData['expiresAt'],
            'createdAt' => $keyData['createdAt'] ?? date('Y-m-d H:i:s'),
        ];
    }

    public function validateKey(string $apiKey): ?string
    {
        if ($apiKey === '' || !is_string($apiKey)) {
            return null;
        }

        $keyHash = hash('sha256', $apiKey);
        $keyData = $this->repo->findByHash($keyHash);

        if ($keyData === null) {
            return null;
        }

        $expiresAt = new \DateTimeImmutable($keyData['expiresAt'], new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($now > $expiresAt) {
            return null;
        }

        return $keyData['userId'];
    }

    public function listKeys(string $userId, int $page, int $limit): array
    {
        return $this->repo->listByUser($userId, $page, $limit);
    }

    public function revokeKey(string $id, string $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }
}
