<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Minecraft;

use InvalidArgumentException;

/**
 * Validierte Query-Parameter des Lese-Endpoints `GET /minecraft/sources`.
 *
 * Der Endpoint liefert eine Gesamtuebersicht (alle Server) oder – bei gesetztem
 * `serverId` – genau einen Server. Geranked wird nach einem einzelnen Zaehler
 * (`sortCategory`/`sortStat`), weil sich "alle Zaehler" nicht sinnvoll ordnen
 * lassen. `limit` trimmt nur die Rangliste; die Summen bleiben vollstaendig.
 */
final readonly class MinecraftStatsQuery
{
    public const string DEFAULT_CATEGORY = 'minecraft:custom';
    public const string DEFAULT_STAT = 'minecraft:play_time';
    public const int MAX_LIMIT = 500;

    private const string SERVER_ID_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';
    private const string KEY_PATTERN = '~^[a-z0-9_.-]+:[a-z0-9_./-]+$~';

    private function __construct(
        public ?string $serverId,
        public string $category,
        public string $stat,
        public ?int $limit,
        public bool $includeStats,
    ) {}

    /**
     * @param array<string, mixed> $params
     *
     * @throws InvalidArgumentException bei ungueltigen Parametern (Fehlercode als Message)
     */
    public static function fromQueryParams(array $params): self
    {
        $serverId = self::stringParam($params, 'serverId', 'invalid_server_id');
        if ($serverId !== null && preg_match(self::SERVER_ID_PATTERN, $serverId) !== 1) {
            throw new InvalidArgumentException('invalid_server_id');
        }

        $category = strtolower(self::stringParam($params, 'sortCategory', 'invalid_sort_category')
            ?? self::DEFAULT_CATEGORY);
        if (preg_match(self::KEY_PATTERN, $category) !== 1) {
            throw new InvalidArgumentException('invalid_sort_category');
        }

        $stat = strtolower(self::stringParam($params, 'sortStat', 'invalid_sort_stat')
            ?? self::DEFAULT_STAT);
        if (preg_match(self::KEY_PATTERN, $stat) !== 1) {
            throw new InvalidArgumentException('invalid_sort_stat');
        }

        $limit = null;
        $rawLimit = self::stringParam($params, 'limit', 'invalid_limit');
        if ($rawLimit !== null) {
            if (preg_match('/^[0-9]+$/', $rawLimit) !== 1) {
                throw new InvalidArgumentException('invalid_limit');
            }

            $limit = (int) $rawLimit;
            if ($limit < 1 || $limit > self::MAX_LIMIT) {
                throw new InvalidArgumentException('invalid_limit');
            }
        }

        $includeStats = self::boolParam($params, 'includeStats');

        return new self($serverId, $category, $stat, $limit, $includeStats);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function stringParam(array $params, string $key, string $error): ?string
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }

        $value = $params[$key];
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($error);
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function boolParam(array $params, string $key): bool
    {
        $value = self::stringParam($params, $key, 'invalid_include_stats');
        if ($value === null) {
            return false;
        }

        $value = strtolower($value);
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException('invalid_include_stats');
    }
}
