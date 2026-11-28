<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

/**
 * Lesezugriff fuer die aggregierte Minecraft-Quellen-Uebersicht
 * (`GET /minecraft/sources`).
 *
 * Aggregiert wird ausschliesslich ueber `MinecraftStatCurrent`. Die
 * Beyond-Verknuepfung wird per `LEFT JOIN` auf `GameAccount` nur angereichert,
 * damit auch Spieler ohne Profil in Summen und Rangliste erscheinen.
 */
final readonly class MinecraftOverviewRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @return list<array{sourceId: int, serverId: string, world: string, lastCollectedAt: string|null}>
     */
    public function listSources(?string $serverId): array
    {
        $sql = 'SELECT s.id AS sourceId, s.serverId, s.world, MAX(sn.collectedAt) AS lastCollectedAt
                FROM MinecraftSource s
                LEFT JOIN MinecraftSnapshot sn ON sn.sourceId = s.id';
        $params = [];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY s.id, s.serverId, s.world
                  ORDER BY s.serverId ASC, s.world ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'sourceId' => (int) $row['sourceId'],
                'serverId' => (string) $row['serverId'],
                'world' => (string) $row['world'],
                'lastCollectedAt' => $row['lastCollectedAt'] !== null ? (string) $row['lastCollectedAt'] : null,
            ];
        }

        return $result;
    }

    /**
     * Summe aller Zaehler je Quelle.
     *
     * @return list<array{sourceId: int, category: string, stat: string, total: int}>
     */
    public function getTotalsBySource(?string $serverId): array
    {
        $sql = 'SELECT c.sourceId, k.category, k.stat, SUM(c.value) AS total
                FROM MinecraftStatCurrent c
                INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId
                INNER JOIN MinecraftSource s ON s.id = c.sourceId';
        $params = [];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY c.sourceId, k.category, k.stat
                  ORDER BY c.sourceId ASC, k.category ASC, k.stat ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'sourceId' => (int) $row['sourceId'],
                'category' => (string) $row['category'],
                'stat' => (string) $row['stat'],
                'total' => (int) $row['total'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, int> Map sourceId => Anzahl Spieler mit mindestens einem Zaehler
     */
    public function getPlayerCountsBySource(?string $serverId): array
    {
        $sql = 'SELECT c.sourceId, COUNT(DISTINCT c.playerUuid) AS playerCount
                FROM MinecraftStatCurrent c
                INNER JOIN MinecraftSource s ON s.id = c.sourceId';
        $params = [];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY c.sourceId';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['sourceId']] = (int) $row['playerCount'];
        }

        return $result;
    }

    /**
     * @return array<string, int> Map serverId => Anzahl eindeutiger Spieler ueber alle Welten
     */
    public function getPlayerCountsByServer(?string $serverId): array
    {
        $sql = 'SELECT s.serverId, COUNT(DISTINCT c.playerUuid) AS playerCount
                FROM MinecraftStatCurrent c
                INNER JOIN MinecraftSource s ON s.id = c.sourceId';
        $params = [];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY s.serverId';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['serverId']] = (int) $row['playerCount'];
        }

        return $result;
    }

    /**
     * Rangliste je Quelle, absteigend nach dem gewaehlten Zaehler.
     *
     * @return list<array{sourceId: int, playerUuid: string, name: string|null, value: int, linkedUserId: string|null, avatarUrl: string|null}>
     */
    public function getRankingBySource(string $category, string $stat, ?string $serverId): array
    {
        $sql = 'SELECT c.sourceId, c.playerUuid, p.name, c.value, ga.userId AS linkedUserId, ga.avatarUrl
                FROM MinecraftStatCurrent c
                INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId AND k.category = ? AND k.stat = ?
                INNER JOIN MinecraftSource s ON s.id = c.sourceId
                INNER JOIN MinecraftPlayer p ON p.uuid = c.playerUuid
                LEFT JOIN GameAccount ga ON ga.game = \'minecraft\' AND ga.accountId = c.playerUuid';
        $params = [$category, $stat];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' ORDER BY c.sourceId ASC, c.value DESC, p.name ASC, c.playerUuid ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'sourceId' => (int) $row['sourceId'],
                'playerUuid' => (string) $row['playerUuid'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'value' => (int) $row['value'],
                'linkedUserId' => $row['linkedUserId'] !== null ? (string) $row['linkedUserId'] : null,
                'avatarUrl' => $row['avatarUrl'] !== null ? (string) $row['avatarUrl'] : null,
            ];
        }

        return $result;
    }

    /**
     * Rangliste je Server, ueber alle Welten summiert.
     *
     * @return list<array{serverId: string, playerUuid: string, name: string|null, value: int, linkedUserId: string|null, avatarUrl: string|null}>
     */
    public function getRankingByServer(string $category, string $stat, ?string $serverId): array
    {
        $sql = 'SELECT s.serverId, c.playerUuid, p.name, SUM(c.value) AS value,
                       ga.userId AS linkedUserId, ga.avatarUrl
                FROM MinecraftStatCurrent c
                INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId AND k.category = ? AND k.stat = ?
                INNER JOIN MinecraftSource s ON s.id = c.sourceId
                INNER JOIN MinecraftPlayer p ON p.uuid = c.playerUuid
                LEFT JOIN GameAccount ga ON ga.game = \'minecraft\' AND ga.accountId = c.playerUuid';
        $params = [$category, $stat];

        if ($serverId !== null) {
            $sql .= ' WHERE s.serverId = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY s.serverId, c.playerUuid, p.name, ga.userId, ga.avatarUrl
                  ORDER BY s.serverId ASC, value DESC, p.name ASC, c.playerUuid ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'serverId' => (string) $row['serverId'],
                'playerUuid' => (string) $row['playerUuid'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'value' => (int) $row['value'],
                'linkedUserId' => $row['linkedUserId'] !== null ? (string) $row['linkedUserId'] : null,
                'avatarUrl' => $row['avatarUrl'] !== null ? (string) $row['avatarUrl'] : null,
            ];
        }

        return $result;
    }

    /**
     * Alle aktuellen Zaehler der angegebenen Spieler je Quelle (nur fuer
     * `includeStats=true`). Die Einschraenkung auf `sourceId` nutzt den
     * Primaerschluessel von `MinecraftStatCurrent`.
     *
     * @param list<int> $sourceIds
     * @param list<string> $playerUuids
     * @return list<array{sourceId: int, playerUuid: string, category: string, stat: string, value: int}>
     */
    public function getSourcePlayerStats(array $sourceIds, array $playerUuids): array
    {
        $result = [];
        foreach ($this->idChunks($sourceIds) as $sourceChunk) {
            foreach ($this->uuidChunks($playerUuids) as $uuidChunk) {
                $sql = 'SELECT c.sourceId, c.playerUuid, k.category, k.stat, c.value
                        FROM MinecraftStatCurrent c
                        INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId
                        WHERE c.sourceId IN (' . $this->placeholders($sourceChunk) . ')
                          AND c.playerUuid IN (' . $this->placeholders($uuidChunk) . ')';

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([...$sourceChunk, ...$uuidChunk]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $result[] = [
                        'sourceId' => (int) $row['sourceId'],
                        'playerUuid' => (string) $row['playerUuid'],
                        'category' => (string) $row['category'],
                        'stat' => (string) $row['stat'],
                        'value' => (int) $row['value'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Alle aktuellen Zaehler der angegebenen Spieler je Server, ueber die
     * Welten summiert (nur fuer `includeStats=true`).
     *
     * @param list<int> $sourceIds
     * @param list<string> $playerUuids
     * @return list<array{serverId: string, playerUuid: string, category: string, stat: string, value: int}>
     */
    public function getServerPlayerStats(array $sourceIds, array $playerUuids): array
    {
        $result = [];
        foreach ($this->idChunks($sourceIds) as $sourceChunk) {
            foreach ($this->uuidChunks($playerUuids) as $uuidChunk) {
                $sql = 'SELECT s.serverId, c.playerUuid, k.category, k.stat, SUM(c.value) AS value
                        FROM MinecraftStatCurrent c
                        INNER JOIN MinecraftStatKey k ON k.id = c.statKeyId
                        INNER JOIN MinecraftSource s ON s.id = c.sourceId
                        WHERE c.sourceId IN (' . $this->placeholders($sourceChunk) . ')
                          AND c.playerUuid IN (' . $this->placeholders($uuidChunk) . ')
                        GROUP BY s.serverId, c.playerUuid, k.category, k.stat';

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([...$sourceChunk, ...$uuidChunk]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $result[] = [
                        'serverId' => (string) $row['serverId'],
                        'playerUuid' => (string) $row['playerUuid'],
                        'category' => (string) $row['category'],
                        'stat' => (string) $row['stat'],
                        'value' => (int) $row['value'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<int> $sourceIds
     * @return list<list<int>>
     */
    private function idChunks(array $sourceIds): array
    {
        $ids = array_values(array_unique($sourceIds));

        return $ids === [] ? [] : array_chunk($ids, self::CHUNK_SIZE);
    }

    /**
     * @param list<string> $playerUuids
     * @return list<list<string>>
     */
    private function uuidChunks(array $playerUuids): array
    {
        $uuids = array_values(array_unique($playerUuids));

        return $uuids === [] ? [] : array_chunk($uuids, self::CHUNK_SIZE);
    }

    /**
     * @param list<int|string> $chunk
     */
    private function placeholders(array $chunk): string
    {
        return implode(',', array_fill(0, count($chunk), '?'));
    }
}
