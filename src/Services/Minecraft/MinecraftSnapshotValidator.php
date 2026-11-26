<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Minecraft;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sinclear\Api\Support\DateTimeValue;

/**
 * Struktur- und Wertpruefung eines Minecraft-Ingest-Snapshots.
 *
 * Die Klasse ist frei von DB-Abhaengigkeiten und damit unit-testbar. Sie
 * validiert den Payload, normalisiert UUIDs (Kleinbuchstaben mit Bindestrichen)
 * und Zeitstempel (UTC mit Millisekunden) und liefert eine normalisierte
 * Struktur, die {@see MinecraftIngestService} verarbeitet.
 */
final readonly class MinecraftSnapshotValidator
{
    private const string STAT_KEY_PATTERN = '~^[a-z0-9_.-]+:[a-z0-9_./-]+$~';
    private const string SERVER_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.\- ]{1,32}$/';

    private const int MAX_DETAILS = 20;
    private const int MAX_KEY_LENGTH = 100;

    public function __construct(
        private int $maxPlayers = 200,
        private int $maxStatsPerPlayer = 10000,
        private int $futureToleranceSeconds = 300,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     schemaVersion: int,
     *     serverId: string,
     *     world: string,
     *     snapshotId: string,
     *     collectedAt: string,
     *     players: list<array{
     *         uuid: string,
     *         name: string|null,
     *         dataVersion: int|null,
     *         stats: list<array{category: string, stat: string, value: int}>
     *     }>
     * }
     *
     * @throws MinecraftValidationException
     */
    public function validate(array $payload): array
    {
        if (($payload['schemaVersion'] ?? null) !== 1) {
            throw new MinecraftValidationException('unsupported_schema_version');
        }

        $details = [];

        $serverId = $this->validateServerId($payload['serverId'] ?? null, $details);
        $world = $this->validateWorld($payload['world'] ?? null, $details);

        $snapshotId = $this->normalizeUuid($payload['snapshotId'] ?? null);
        if ($snapshotId === null) {
            $this->addDetail($details, 'snapshotId', 'invalid_uuid');
        }

        $collectedAt = $this->parseCollectedAt($payload['collectedAt'] ?? null, $details);

        $players = $this->validatePlayers($payload['players'] ?? null, $details);

        if ($details !== []) {
            throw new MinecraftValidationException('validation_failed', $details);
        }

        return [
            'schemaVersion' => 1,
            'serverId' => $serverId,
            'world' => $world,
            'snapshotId' => $snapshotId,
            'collectedAt' => $collectedAt,
            'players' => $players,
        ];
    }

    /**
     * @param list<array{field: string, error: string}> $details
     */
    private function validateServerId(mixed $value, array &$details): string
    {
        if (is_string($value) && preg_match(self::SERVER_PATTERN, $value) === 1) {
            return $value;
        }

        $this->addDetail($details, 'serverId', 'invalid_server_id');
        return '';
    }

    /**
     * @param list<array{field: string, error: string}> $details
     */
    private function validateWorld(mixed $value, array &$details): string
    {
        if (is_string($value) && preg_match(self::SERVER_PATTERN, $value) === 1) {
            return $value;
        }

        $this->addDetail($details, 'world', 'invalid_world');
        return '';
    }

    /**
     * @param list<array{field: string, error: string}> $details
     */
    private function parseCollectedAt(mixed $value, array &$details): string
    {
        if (!is_string($value)) {
            $this->addDetail($details, 'collectedAt', 'invalid_timestamp');
            return '';
        }

        try {
            $instant = DateTimeValue::parseInstant($value);
        } catch (InvalidArgumentException) {
            $this->addDetail($details, 'collectedAt', 'invalid_timestamp');
            return '';
        }

        $limit = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->futureToleranceSeconds . ' seconds');

        if ($instant > $limit) {
            $this->addDetail($details, 'collectedAt', 'future_timestamp');
            return '';
        }

        return $instant->format('Y-m-d H:i:s.v');
    }

    /**
     * @return list<array{
     *     uuid: string,
     *     name: string|null,
     *     dataVersion: int|null,
     *     stats: list<array{category: string, stat: string, value: int}>
     * }>
     *
     * @param list<array{field: string, error: string}> $details
     */
    private function validatePlayers(mixed $value, array &$details): array
    {
        if (!is_array($value)) {
            $this->addDetail($details, 'players', 'required');
            return [];
        }

        $count = count($value);
        if ($count < 1) {
            $this->addDetail($details, 'players', 'too_few_players');
        }
        if ($count > $this->maxPlayers) {
            $this->addDetail($details, 'players', 'too_many_players');
        }

        $seenUuids = [];
        $players = [];

        foreach ($value as $i => $player) {
            $prefix = 'players.' . $i;

            if (!is_array($player)) {
                $this->addDetail($details, $prefix, 'invalid_type');
                continue;
            }

            $uuid = $this->normalizeUuid($player['uuid'] ?? null);
            if ($uuid === null) {
                $this->addDetail($details, $prefix . '.uuid', 'invalid_uuid');
            } elseif (isset($seenUuids[$uuid])) {
                $this->addDetail($details, $prefix . '.uuid', 'duplicate_uuid');
            } else {
                $seenUuids[$uuid] = true;
            }

            $name = $this->validateName($player, $prefix, $details);
            $dataVersion = $this->validateDataVersion($player, $prefix, $details);
            $stats = $this->validateStats($player['stats'] ?? null, $prefix, $details);

            $players[] = [
                'uuid' => $uuid ?? '',
                'name' => $name,
                'dataVersion' => $dataVersion,
                'stats' => $stats,
            ];
        }

        return $players;
    }

    /**
     * @param array<string, mixed> $player
     * @param list<array{field: string, error: string}> $details
     */
    private function validateName(array $player, string $prefix, array &$details): ?string
    {
        if (!array_key_exists('name', $player) || $player['name'] === null) {
            return null;
        }

        $name = $player['name'];
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $this->addDetail($details, $prefix . '.name', 'invalid_name');
            return null;
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $player
     * @param list<array{field: string, error: string}> $details
     */
    private function validateDataVersion(array $player, string $prefix, array &$details): ?int
    {
        if (!array_key_exists('dataVersion', $player) || $player['dataVersion'] === null) {
            return null;
        }

        $value = $player['dataVersion'];
        if (!is_int($value)) {
            $this->addDetail($details, $prefix . '.dataVersion', 'invalid_type');
            return null;
        }

        return $value;
    }

    /**
     * @return list<array{category: string, stat: string, value: int}>
     *
     * @param list<array{field: string, error: string}> $details
     */
    private function validateStats(mixed $value, string $prefix, array &$details): array
    {
        if (!is_array($value)) {
            $this->addDetail($details, $prefix . '.stats', 'required');
            return [];
        }

        $stats = [];
        $count = 0;

        foreach ($value as $category => $categoryStats) {
            if (!is_string($category) || !$this->isValidKey($category)) {
                $this->addDetail($details, $prefix . '.stats', 'invalid_stat_key');
                continue;
            }

            if (!is_array($categoryStats)) {
                $this->addDetail($details, $prefix . '.stats', 'invalid_stats');
                continue;
            }

            foreach ($categoryStats as $stat => $statValue) {
                if (!is_string($stat) || !$this->isValidKey($stat)) {
                    $this->addDetail($details, $prefix . '.stats', 'invalid_stat_key');
                    continue;
                }

                if (!is_int($statValue) || $statValue < 0) {
                    $this->addDetail($details, $prefix . '.stats', 'invalid_stat_value');
                    continue;
                }

                if ($count >= $this->maxStatsPerPlayer) {
                    $this->addDetail($details, $prefix . '.stats', 'too_many_stats');
                    break 2;
                }

                $stats[] = [
                    'category' => $category,
                    'stat' => $stat,
                    'value' => $statValue,
                ];
                $count++;
            }
        }

        return $stats;
    }

    private function isValidKey(string $key): bool
    {
        return strlen($key) <= self::MAX_KEY_LENGTH
            && preg_match(self::STAT_KEY_PATTERN, $key) === 1;
    }

    private function normalizeUuid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $uuid = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1) {
            return $uuid;
        }

        if (preg_match('/^[0-9a-f]{32}$/', $uuid) === 1) {
            return substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4)
                . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20, 12);
        }

        return null;
    }

    /**
     * @param list<array{field: string, error: string}> $details
     */
    private function addDetail(array &$details, string $field, string $error): void
    {
        if (count($details) < self::MAX_DETAILS) {
            $details[] = ['field' => $field, 'error' => $error];
        }
    }
}
