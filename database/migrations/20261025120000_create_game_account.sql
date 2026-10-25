-- Migration: Tabelle GameAccount fuer verknuepfte Spiele-Accounts
-- Autor: SinclearAPI Agent
--
-- In der DB liegen ausschliesslich Profil-/Identitaetsdaten der verknuepften
-- Spiele-Accounts. Statistiken werden nicht in der DB gespeichert, sondern im
-- Dateisystem unter var/cache/game-stats/ gecacht.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. CREATE TABLE IF NOT EXISTS ist
-- rerun-sicher.

CREATE TABLE IF NOT EXISTS `GameAccount` (
  `id`            varchar(191)  NOT NULL,
  `userId`        varchar(191)  NOT NULL,
  `game`          varchar(50)   NOT NULL,
  `accountId`     varchar(191)  NOT NULL,
  `displayName`   varchar(191)  DEFAULT NULL,
  `avatarUrl`     varchar(1024) DEFAULT NULL,
  `profile`       JSON          DEFAULT NULL,
  `lastSyncedAt`  datetime(3)   DEFAULT NULL,
  `createdAt`     datetime(3)   NOT NULL,
  `updatedAt`     datetime(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `GameAccount_user_game_key` (`userId`, `game`),
  KEY `GameAccount_game_accountId_idx` (`game`, `accountId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
