-- Wiederverwendbare Unterkuenfte (globaler Katalog).
--
-- Unterkuenfte waren bisher ueber `TravelAccommodation.tripId` fest an eine
-- einzige Reise gebunden. Damit sie einmal angelegt und in beliebig vielen
-- Reisen wiederverwendet werden koennen, wird eine n:m-Verknuepfung
-- (`TravelAccommodationTrip`) eingefuehrt:
--
--   TravelAccommodation        = globaler Katalog (jeder Nutzer darf waehlen)
--   TravelAccommodationTrip    = "Unterkunft ist Teil dieser Reise"
--   TravelRelation.accommodation = "dieser Teilnehmer ist dieser Unterkunft
--                                   innerhalb dieser Reise zugeordnet"
--
-- `TravelAccommodation.tripId` bleibt bestehen (Bestandsschutz); neue
-- Unterkuenfte werden ohne tripId angelegt und ausschliesslich ueber die
-- Junction verknuepft. `createdBy` ermoeglicht dem Ersteller (oder Admins)
-- das endgueltige Loeschen.
--
-- Bestandsunterkuenfte mit gesetzter `tripId` werden in die Junction
-- uebernommen.

-- 1) createdBy-Spalte
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelAccommodation'
      AND COLUMN_NAME = 'createdBy'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `TravelAccommodation`
       ADD COLUMN `createdBy` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
       ADD KEY `idx_travelaccommodation_createdby` (`createdBy`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Junction-Tabelle (n:m)
CREATE TABLE IF NOT EXISTS `TravelAccommodationTrip` (
  `ID` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accommodationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uniq_travelaccommodationtrip` (`tripid`,`accommodationId`),
  KEY `idx_travelaccommodationtrip_acc` (`accommodationId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Bestandsunterkuenfte uebernehmen
SET @has_tripid := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelAccommodation'
      AND COLUMN_NAME = 'tripId'
);

SET @sql := IF(
    @has_tripid > 0,
    'INSERT IGNORE INTO `TravelAccommodationTrip` (`ID`, `tripid`, `accommodationId`)
       SELECT UUID(), `tripId`, `ID`
       FROM `TravelAccommodation`
       WHERE `tripId` IS NOT NULL AND `tripId` <> ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
