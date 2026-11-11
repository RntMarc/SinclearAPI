-- Migration: AdminAuditLog-Tabelle fuer Audit-Logging von Admin-Aktionen
-- Autor: SinclearAPI Agent
--
-- Jede mutierende Admin-Aktion (create/update/delete/approve/reject/close/...)
-- wird mit Admin-ID, Aktion, Entitaetstyp/-ID, IP-Adresse und User-Agent
-- protokolliert. Lesende Zugriffe werden NICHT protokolliert.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. CREATE TABLE IF NOT EXISTS ist
-- rerun-sicher.

CREATE TABLE IF NOT EXISTS `AdminAuditLog` (
  `id`         varchar(191) NOT NULL,
  `adminId`    varchar(191) NOT NULL,
  `action`     varchar(80)  NOT NULL,
  `entityType` varchar(80)  NOT NULL,
  `entityId`   varchar(191) DEFAULT NULL,
  `ipAddress`  varchar(64)  DEFAULT NULL,
  `userAgent`  varchar(512) DEFAULT NULL,
  `createdAt`  datetime(3)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `AdminAuditLog_admin_created_idx` (`adminId`, `createdAt`),
  KEY `AdminAuditLog_entity_idx` (`entityType`, `entityId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
