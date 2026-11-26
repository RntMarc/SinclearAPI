<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

/**
 * Bekannte Minecraft-Spieler (global ueber alle Server/Welten hinweg).
 *
 * `firstSeenAt` wird auf den fruehesten, `lastSeenAt` auf den spaetesten
 * Snapshot gehalten, in dem der Spieler vorkam. Der Name wird nur aus einem
 * Snapshot uebernommen, der nicht aelter als der bisherige `lastSeenAt` ist,
 * damit Nachzuegler keinen neueren Namen ueberschreiben.
 */
final readonly class MinecraftPlayerRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param list<array{uuid: string, name: string|null, collectedAt: string}> $players
     */
    public function upsertMany(array $players): void
    {
        if ($players === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO MinecraftPlayer (uuid, name, firstSeenAt, lastSeenAt)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               name = IF(VALUES(lastSeenAt) >= lastSeenAt, COALESCE(VALUES(name), name), name),
               firstSeenAt = LEAST(firstSeenAt, VALUES(firstSeenAt)),
               lastSeenAt = GREATEST(lastSeenAt, VALUES(lastSeenAt))'
        );

        foreach ($players as $player) {
            $stmt->execute([
                $player['uuid'],
                $player['name'],
                $player['collectedAt'],
                $player['collectedAt'],
            ]);
        }
    }
}
