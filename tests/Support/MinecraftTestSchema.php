<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Support;

use PDO;

/**
 * Erzeugt das Minecraft-Ingest-Schema direkt in der Testdatenbank.
 *
 * Bewusst als PHP-Definition statt als `.sql`-Datei: Ausgefuehrte Migrationen
 * werden im Projekt nach dem Anwenden geloescht; die Tests sollen davon
 * unabhaengig bleiben und keine Datei aus `database/migrations/` laden.
 */
final class MinecraftTestSchema
{
    /** @var list<string> */
    private const array DROP_TABLES = [
        'MinecraftStatHistory',
        'MinecraftStatCurrent',
        'MinecraftStatKey',
        'MinecraftSnapshotPlayer',
        'MinecraftSnapshot',
        'MinecraftPlayer',
        'MinecraftSource',
        // Wird vom Ingest-Test fuer die Verknuepfung angelegt.
        'GameAccount',
    ];

    /** @var list<string> */
    private const array CREATE_STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS `MinecraftSource` (
            `id` int NOT NULL AUTO_INCREMENT,
            `serverId` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `world` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_minecraft_source_server_world` (`serverId`, `world`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftPlayer` (
            `uuid` char(36) CHARACTER SET ascii NOT NULL,
            `name` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `firstSeenAt` datetime(3) NOT NULL,
            `lastSeenAt` datetime(3) NOT NULL,
            PRIMARY KEY (`uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftSnapshot` (
            `id` char(36) CHARACTER SET ascii NOT NULL,
            `sourceId` int NOT NULL,
            `schemaVersion` smallint NOT NULL,
            `collectedAt` datetime(3) NOT NULL,
            `receivedAt` datetime(3) NOT NULL,
            `playerCount` smallint NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_minecraft_snapshot_source_collected` (`sourceId`, `collectedAt`),
            CONSTRAINT `fk_minecraft_snapshot_source` FOREIGN KEY (`sourceId`) REFERENCES `MinecraftSource` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftSnapshotPlayer` (
            `snapshotId` char(36) CHARACTER SET ascii NOT NULL,
            `playerUuid` char(36) CHARACTER SET ascii NOT NULL,
            `dataVersion` int DEFAULT NULL,
            PRIMARY KEY (`snapshotId`, `playerUuid`),
            KEY `idx_minecraft_snapshot_player_uuid` (`playerUuid`),
            CONSTRAINT `fk_minecraft_snapshot_player_snapshot` FOREIGN KEY (`snapshotId`) REFERENCES `MinecraftSnapshot` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_minecraft_snapshot_player_player` FOREIGN KEY (`playerUuid`) REFERENCES `MinecraftPlayer` (`uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftStatKey` (
            `id` int NOT NULL AUTO_INCREMENT,
            `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `stat` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_minecraft_stat_key_category_stat` (`category`, `stat`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftStatCurrent` (
            `sourceId` int NOT NULL,
            `playerUuid` char(36) CHARACTER SET ascii NOT NULL,
            `statKeyId` int NOT NULL,
            `value` bigint NOT NULL,
            `collectedAt` datetime(3) NOT NULL,
            `snapshotId` char(36) CHARACTER SET ascii NOT NULL,
            PRIMARY KEY (`sourceId`, `playerUuid`, `statKeyId`),
            CONSTRAINT `fk_minecraft_stat_current_source` FOREIGN KEY (`sourceId`) REFERENCES `MinecraftSource` (`id`),
            CONSTRAINT `fk_minecraft_stat_current_player` FOREIGN KEY (`playerUuid`) REFERENCES `MinecraftPlayer` (`uuid`),
            CONSTRAINT `fk_minecraft_stat_current_key` FOREIGN KEY (`statKeyId`) REFERENCES `MinecraftStatKey` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `MinecraftStatHistory` (
            `sourceId` int NOT NULL,
            `playerUuid` char(36) CHARACTER SET ascii NOT NULL,
            `statKeyId` int NOT NULL,
            `collectedAt` datetime(3) NOT NULL,
            `value` bigint NOT NULL,
            `snapshotId` char(36) CHARACTER SET ascii NOT NULL,
            PRIMARY KEY (`sourceId`, `playerUuid`, `statKeyId`, `collectedAt`),
            KEY `idx_minecraft_stat_history_snapshot` (`snapshotId`),
            CONSTRAINT `fk_minecraft_stat_history_source` FOREIGN KEY (`sourceId`) REFERENCES `MinecraftSource` (`id`),
            CONSTRAINT `fk_minecraft_stat_history_player` FOREIGN KEY (`playerUuid`) REFERENCES `MinecraftPlayer` (`uuid`),
            CONSTRAINT `fk_minecraft_stat_history_key` FOREIGN KEY (`statKeyId`) REFERENCES `MinecraftStatKey` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    public static function drop(PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::DROP_TABLES as $table) {
            $db->exec("DROP TABLE IF EXISTS `$table`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function create(PDO $db): void
    {
        foreach (self::CREATE_STATEMENTS as $statement) {
            $db->exec($statement);
        }
    }
}
