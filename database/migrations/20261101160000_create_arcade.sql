-- Migration: Arcade-Tabellen fuer selbst gehostete Browser-Spiele
-- Autor: SinclearAPI Agent
--
-- Arcade ist ein eigener Namensraum und unabhaengig von GameAccount/GameStats.
-- Die Spiel-Dateien liegen statisch unter public/arcade/<slug>/; in der DB
-- stehen nur Metadaten, Session-Token und Score-Historie.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. CREATE TABLE IF NOT EXISTS ist
-- rerun-sicher.

CREATE TABLE IF NOT EXISTS `ArcadeGame` (
  `id`             varchar(191)  NOT NULL,
  `slug`           varchar(50)   NOT NULL,
  `title`          varchar(191)  NOT NULL,
  `description`    text          DEFAULT NULL,
  `thumbnailUrl`   varchar(1024) DEFAULT NULL,
  `scoreDirection` enum('asc','desc') NOT NULL DEFAULT 'desc',
  `isActive`       tinyint(1)    NOT NULL DEFAULT 1,
  `sortOrder`      int           NOT NULL DEFAULT 0,
  `createdAt`      datetime(3)   NOT NULL,
  `updatedAt`      datetime(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ArcadeGame_slug_key` (`slug`),
  KEY `ArcadeGame_active_order_idx` (`isActive`, `sortOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ArcadeSession` (
  `id`         varchar(191)  NOT NULL,
  `userId`     varchar(191)  NOT NULL,
  `gameId`     varchar(191)  NOT NULL,
  `tokenHash`  varchar(64)   NOT NULL,
  `expiresAt`  datetime(3)   NOT NULL,
  `revokedAt`  datetime(3)   DEFAULT NULL,
  `createdAt`  datetime(3)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ArcadeSession_user_game_idx` (`userId`, `gameId`),
  KEY `ArcadeSession_tokenHash_idx` (`tokenHash`),
  KEY `ArcadeSession_expiresAt_idx` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ArcadeScore` (
  `id`        varchar(191)  NOT NULL,
  `userId`    varchar(191)  NOT NULL,
  `gameId`    varchar(191)  NOT NULL,
  `score`     bigint        NOT NULL,
  `meta`      JSON          DEFAULT NULL,
  `createdAt` datetime(3)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ArcadeScore_game_score_idx` (`gameId`, `score`),
  KEY `ArcadeScore_game_user_idx` (`gameId`, `userId`),
  KEY `ArcadeScore_user_game_idx` (`userId`, `gameId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
