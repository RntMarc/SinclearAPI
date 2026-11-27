<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

/**
 * Datenzugriff fuer die Minecraft-Statistik-Tabellen:
 *
 *   - MinecraftSource        (Server + Welt als Woerterbuch)
 *   - MinecraftStatKey       (Statistik-Woerterbuch)
 *   - MinecraftStatCurrent   (aktueller Stand je Zaehler)
 *   - MinecraftStatHistory   (Verlauf, nur Aenderungen)
 *   - MinecraftSnapshotPlayer (Anwesenheit je Snapshot)
 */
final readonly class MinecraftStatRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private PDO $pdo,
    ) {}

    public function getOrCreateSource(string $serverId, string $world): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftSource (serverId, world) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$serverId, $world]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Liefert fuer jedes (category, stat)-Paar die ID aus `MinecraftStatKey`.
     * Noch nicht vorhandene Schluessel werden angelegt.
     *
     * @param list<array{category: string, stat: string}> $keys
     * @return array<string, int> Map "category\0stat" => id
     */
    public function getOrCreateStatKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $unique = [];
        foreach ($keys as $key) {
            $unique[$key['category'] . "\0" . $key['stat']] = $key;
        }
        $list = array_values($unique);

        $insert = $this->pdo->prepare(
            'INSERT INTO MinecraftStatKey (category, stat) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        foreach (array_chunk($list, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $key) {
                $insert->execute([$key['category'], $key['stat']]);
            }
        }

        $map = [];
        foreach (array_chunk($list, self::CHUNK_SIZE) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $key) {
                $placeholders[] = '(?, ?)';
                $params[] = $key['category'];
                $params[] = $key['stat'];
            }

            $select = $this->pdo->prepare(
                'SELECT id, category, stat FROM MinecraftStatKey WHERE (category, stat) IN ('
                . implode(',', $placeholders) . ')'
            );
            $select->execute($params);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[$row['category'] . "\0" . $row['stat']] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * Aktueller Stand aller Zaehler eines Spielers (je Quelle).
     *
     * @return array<int, array{value: int, collectedAt: string}> Map statKeyId => Wert
     */
    public function getCurrentForPlayer(int $sourceId, string $playerUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT statKeyId, value, collectedAt
             FROM MinecraftStatCurrent
             WHERE sourceId = ? AND playerUuid = ?'
        );
        $stmt->execute([$sourceId, $playerUuid]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['statKeyId']] = [
                'value' => (int) $row['value'],
                'collectedAt' => (string) $row['collectedAt'],
            ];
        }

        return $result;
    }

    /**
     * Letzter History-Wert jedes Zaehlers vor `$collectedAt` (fuer Nachzuegler).
     *
     * @return array<int, int> Map statKeyId => Wert
     */
    public function getHistoryBefore(int $sourceId, string $playerUuid, string $collectedAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.statKeyId, h.value
             FROM MinecraftStatHistory h
             INNER JOIN (
                 SELECT statKeyId, MAX(collectedAt) AS maxAt
                 FROM MinecraftStatHistory
                 WHERE sourceId = ? AND playerUuid = ? AND collectedAt < ?
                 GROUP BY statKeyId
             ) m ON m.statKeyId = h.statKeyId AND m.maxAt = h.collectedAt
             WHERE h.sourceId = ? AND h.playerUuid = ? AND h.collectedAt < ?'
        );
        $stmt->execute([$sourceId, $playerUuid, $collectedAt, $sourceId, $playerUuid, $collectedAt]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['statKeyId']] = (int) $row['value'];
        }

        return $result;
    }

    /**
     * @param list<array{statKeyId: int, value: int}> $rows
     */
    public function insertHistoryMany(
        int $sourceId,
        string $playerUuid,
        string $collectedAt,
        string $snapshotId,
        array $rows,
    ): void {
        if ($rows === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftStatHistory (sourceId, playerUuid, statKeyId, collectedAt, value, snapshotId)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), snapshotId = VALUES(snapshotId)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                $sourceId,
                $playerUuid,
                $row['statKeyId'],
                $collectedAt,
                $row['value'],
                $snapshotId,
            ]);
        }
    }

    /**
     * @param list<array{statKeyId: int, value: int}> $rows
     */
    public function upsertCurrentMany(
        int $sourceId,
        string $playerUuid,
        string $collectedAt,
        string $snapshotId,
        array $rows,
    ): void {
        if ($rows === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftStatCurrent (sourceId, playerUuid, statKeyId, value, collectedAt, snapshotId)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               value = VALUES(value), collectedAt = VALUES(collectedAt), snapshotId = VALUES(snapshotId)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                $sourceId,
                $playerUuid,
                $row['statKeyId'],
                $row['value'],
                $collectedAt,
                $snapshotId,
            ]);
        }
    }

    /**
     * @param list<array{playerUuid: string, dataVersion: int|null}> $players
     */
    public function insertSnapshotPlayers(string $snapshotId, array $players): void
    {
        if ($players === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftSnapshotPlayer (snapshotId, playerUuid, dataVersion)
             VALUES (?, ?, ?)'
        );

        foreach ($players as $player) {
            $stmt->execute([$snapshotId, $player['playerUuid'], $player['dataVersion']]);
        }
    }

    /**
     * Aktueller Stand aller Zaehler eines Spielers ueber alle Quellen hinweg.
     *
     * @return list<array{sourceId: int, serverId: string, world: string, category: string, stat: string, value: int, collectedAt: string}>
     */
    public function getCurrentStatsForPlayer(string $playerUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.sourceId, s.serverId, s.world, k.category, k.stat, c.value, c.collectedAt
             FROM MinecraftStatCurrent c
             INNER JOIN MinecraftSource s ON s.id = c.sourceId
             INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId
             WHERE c.playerUuid = ?
             ORDER BY s.serverId ASC, s.world ASC, k.category ASC, k.stat ASC'
        );
        $stmt->execute([$playerUuid]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'sourceId' => (int) $row['sourceId'],
                'serverId' => (string) $row['serverId'],
                'world' => (string) $row['world'],
                'category' => (string) $row['category'],
                'stat' => (string) $row['stat'],
                'value' => (int) $row['value'],
                'collectedAt' => (string) $row['collectedAt'],
            ];
        }

        return $result;
    }

    /**
     * Juengster `collectedAt` ueber alle aktuellen Staende (fuer die
     * Cache-Metadaten des Push-Modells).
     */
    public function latestCollectedAt(): ?string
    {
        $stmt = $this->pdo->query('SELECT MAX(collectedAt) FROM MinecraftStatCurrent');
        if ($stmt === false) {
            return null;
        }

        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }
}
