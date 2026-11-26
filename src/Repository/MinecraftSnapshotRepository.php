<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

/**
 * Eingangsprotokoll der Minecraft-Ingest-Snapshots.
 *
 * `id` ist zugleich der Idempotenz-Schluessel (die `snapshotId` des Payloads).
 * Ein Duplikat schlaegt mit einem Primaerschluessel-Konflikt (SQLSTATE 23000,
 * Driver-Code 1062) fehl und wird vom Service als Duplikat behandelt.
 */
final readonly class MinecraftSnapshotRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function exists(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM MinecraftSnapshot WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    public function insert(
        string $id,
        int $sourceId,
        int $schemaVersion,
        string $collectedAt,
        string $receivedAt,
        int $playerCount,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftSnapshot (id, sourceId, schemaVersion, collectedAt, receivedAt, playerCount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $sourceId, $schemaVersion, $collectedAt, $receivedAt, $playerCount]);
    }
}
