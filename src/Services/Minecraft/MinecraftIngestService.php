<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Minecraft;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\MinecraftPlayerRepository;
use Sinclear\Api\Repository\MinecraftSnapshotRepository;
use Sinclear\Api\Repository\MinecraftStatRepository;

/**
 * Speichert einen validierten Minecraft-Ingest-Snapshot.
 *
 * Der Snapshot wird ganz oder gar nicht gespeichert (eine Transaktion). Die
 * `snapshotId` ist der Idempotenz-Schluessel; ein bereits bekannter Snapshot
 * liefert `duplicate: true`. Nachzuegler (`collectedAt` aelter als der aktuelle
 * Stand eines Spielers) werden in den Verlauf eingeordnet, veraendern den
 * aktuellen Stand aber nicht.
 */
final readonly class MinecraftIngestService
{
    public function __construct(
        private PDO $pdo,
        private MinecraftSnapshotRepository $snapshotRepo,
        private MinecraftPlayerRepository $playerRepo,
        private MinecraftStatRepository $statRepo,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array{
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
     * } $payload
     *
     * @return array{snapshotId: string, duplicate: bool, accepted: int, changedStats: int}
     */
    public function ingest(array $payload): array
    {
        $snapshotId = $payload['snapshotId'];
        $serverId = $payload['serverId'];
        $world = $payload['world'];
        $collectedAt = $payload['collectedAt'];
        $players = $payload['players'];

        $receivedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->pdo->beginTransaction();
        try {
            if ($this->snapshotRepo->exists($snapshotId)) {
                $this->pdo->rollBack();
                return $this->duplicate($snapshotId);
            }

            $sourceId = $this->statRepo->getOrCreateSource($serverId, $world);

            $this->snapshotRepo->insert(
                id: $snapshotId,
                sourceId: $sourceId,
                schemaVersion: $payload['schemaVersion'],
                collectedAt: $collectedAt,
                receivedAt: $receivedAt,
                playerCount: count($players),
            );

            $keyIdMap = $this->statRepo->getOrCreateStatKeys($this->collectStatKeys($players));

            $this->playerRepo->upsertMany(array_map(
                static fn (array $player): array => [
                    'uuid' => $player['uuid'],
                    'name' => $player['name'],
                    'collectedAt' => $collectedAt,
                ],
                $players,
            ));

            $this->statRepo->insertSnapshotPlayers($snapshotId, array_map(
                static fn (array $player): array => [
                    'playerUuid' => $player['uuid'],
                    'dataVersion' => $player['dataVersion'],
                ],
                $players,
            ));

            $changedStats = 0;
            foreach ($players as $player) {
                $changedStats += $this->storePlayerStats($sourceId, $player, $collectedAt, $snapshotId, $keyIdMap);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($this->isDuplicateKey($e)) {
                return $this->duplicate($snapshotId);
            }
            throw $e;
        }

        $this->logger->info('[MinecraftIngest] snapshot stored', [
            'snapshotId' => $snapshotId,
            'serverId' => $serverId,
            'playerCount' => count($players),
            'changedStats' => $changedStats,
        ]);

        return [
            'snapshotId' => $snapshotId,
            'duplicate' => false,
            'accepted' => count($players),
            'changedStats' => $changedStats,
        ];
    }

    /**
     * @param array{
     *     uuid: string,
     *     name: string|null,
     *     dataVersion: int|null,
     *     stats: list<array{category: string, stat: string, value: int}>
     * } $player
     * @param array<string, int> $keyIdMap
     */
    private function storePlayerStats(
        int $sourceId,
        array $player,
        string $collectedAt,
        string $snapshotId,
        array $keyIdMap,
    ): int {
        $uuid = $player['uuid'];

        $incoming = [];
        foreach ($player['stats'] as $stat) {
            $keyId = $keyIdMap[$stat['category'] . "\0" . $stat['stat']] ?? null;
            if ($keyId === null) {
                continue;
            }
            $incoming[$keyId] = $stat['value'];
        }

        if ($incoming === []) {
            return 0;
        }

        $current = $this->statRepo->getCurrentForPlayer($sourceId, $uuid);

        // Nur noetig, wenn mindestens ein eingehender Zaehler bereits einen
        // juengeren Stand als `collectedAt` hat (Nachzuegler).
        $historyBefore = null;
        foreach ($incoming as $statKeyId => $_) {
            $cur = $current[$statKeyId] ?? null;
            if ($cur !== null && $cur['collectedAt'] > $collectedAt) {
                $historyBefore = $this->statRepo->getHistoryBefore($sourceId, $uuid, $collectedAt);
                break;
            }
        }

        $historyRows = [];
        $currentUpserts = [];

        foreach ($incoming as $statKeyId => $value) {
            $cur = $current[$statKeyId] ?? null;

            if ($cur === null) {
                $previous = null;
                $updateCurrent = true;
            } elseif ($cur['collectedAt'] <= $collectedAt) {
                $previous = $cur['value'];
                $updateCurrent = true;
            } else {
                $previous = $historyBefore[$statKeyId] ?? null;
                $updateCurrent = false;
            }

            if ($previous === null || $previous !== $value) {
                $historyRows[] = ['statKeyId' => $statKeyId, 'value' => $value];

                if ($updateCurrent) {
                    $currentUpserts[] = ['statKeyId' => $statKeyId, 'value' => $value];
                }
            }
        }

        $this->statRepo->insertHistoryMany($sourceId, $uuid, $collectedAt, $snapshotId, $historyRows);

        if ($currentUpserts !== []) {
            $this->statRepo->upsertCurrentMany($sourceId, $uuid, $collectedAt, $snapshotId, $currentUpserts);
        }

        return count($historyRows);
    }

    /**
     * @param list<array{uuid: string, name: string|null, dataVersion: int|null, stats: list<array{category: string, stat: string, value: int}>}> $players
     * @return list<array{category: string, stat: string}>
     */
    private function collectStatKeys(array $players): array
    {
        $keys = [];
        foreach ($players as $player) {
            foreach ($player['stats'] as $stat) {
                $keys[] = ['category' => $stat['category'], 'stat' => $stat['stat']];
            }
        }
        return $keys;
    }

    /**
     * @return array{snapshotId: string, duplicate: bool, accepted: int, changedStats: int}
     */
    private function duplicate(string $snapshotId): array
    {
        $this->logger->info('[MinecraftIngest] duplicate snapshot ignored', ['snapshotId' => $snapshotId]);

        return [
            'snapshotId' => $snapshotId,
            'duplicate' => true,
            'accepted' => 0,
            'changedStats' => 0,
        ];
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
    }
}
