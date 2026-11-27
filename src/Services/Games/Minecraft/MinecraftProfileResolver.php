<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Minecraft;

use InvalidArgumentException;

/**
 * Loest die Nutzereingabe (UUID oder Mojang-Username) beim Verknuepfen eines
 * Minecraft-Accounts auf. Liefert die kanonische UUID (lowercase, mit
 * Bindestrichen), den Anzeigenamen und die Avatar-URL.
 */
final readonly class MinecraftProfileResolver
{
    private const string USERNAME_PATTERN = '/^[A-Za-z0-9_]{3,16}$/';

    public function __construct(
        private MinecraftClient $client,
    ) {}

    /**
     * @return array{accountId: string, displayName: string|null, avatarUrl: string, profile: array<string, mixed>}
     */
    public function resolve(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('invalid_account_id');
        }

        $uuid = self::normalizeUuid($input);
        if ($uuid !== null) {
            return $this->resolveByUuid($uuid);
        }

        if (!preg_match(self::USERNAME_PATTERN, $input)) {
            throw new InvalidArgumentException('invalid_account_id');
        }

        return $this->resolveByUsername($input);
    }

    /**
     * Normalisiert eine UUID (mit oder ohne Bindestriche) auf das kanonische
     * Kleinbuchstaben-Format mit Bindestrichen. Gibt `null` zurueck, wenn die
     * Eingabe keine UUID ist.
     */
    public static function normalizeUuid(string $input): ?string
    {
        $hex = str_replace('-', '', $input);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            return null;
        }

        return strtolower(
            substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12)
        );
    }

    /**
     * @return array{accountId: string, displayName: string|null, avatarUrl: string, profile: array<string, mixed>}
     */
    private function resolveByUuid(string $uuid): array
    {
        $profile = $this->lookup(
            fn (): ?array => $this->client->findByUuid($uuid),
        );

        if ($profile === null) {
            throw new InvalidArgumentException('account_not_found');
        }

        $canonical = self::normalizeUuid($profile['id']) ?? $profile['id'];

        return $this->profileResult($canonical, $profile['name'] !== '' ? $profile['name'] : null);
    }

    /**
     * @return array{accountId: string, displayName: string|null, avatarUrl: string, profile: array<string, mixed>}
     */
    private function resolveByUsername(string $username): array
    {
        $profile = $this->lookup(
            fn (): ?array => $this->client->findByName($username),
        );

        if ($profile === null) {
            throw new InvalidArgumentException('account_not_found');
        }

        $canonical = self::normalizeUuid($profile['id']) ?? $profile['id'];

        return $this->profileResult($canonical, $profile['name']);
    }

    /**
     * @param callable(): (array{id: string, name: string}|null) $lookup
     * @return array{id: string, name: string}|null
     */
    private function lookup(callable $lookup): ?array
    {
        try {
            return $lookup();
        } catch (MinecraftApiException $e) {
            if ($e->status === 0 || $e->status === 429 || $e->status >= 500) {
                throw new InvalidArgumentException('game_api_unavailable');
            }

            throw new InvalidArgumentException('account_not_found');
        }
    }

    /**
     * @return array{accountId: string, displayName: string|null, avatarUrl: string, profile: array<string, mixed>}
     */
    private function profileResult(string $uuid, ?string $name): array
    {
        return [
            'accountId' => $uuid,
            'displayName' => $name,
            'avatarUrl' => $this->client->avatarUrl($uuid),
            'profile' => ['username' => $name],
        ];
    }
}
