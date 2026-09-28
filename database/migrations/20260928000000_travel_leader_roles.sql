-- Reiseleiter-/Teilnehmerrollen fuer Reisen und Events.
--
-- Fuehrt eine Rolle pro Teilnehmer-Relation ein:
--   TravelRelation.role : 'leader' = Reiseleiter (darf die Reise und ihre
--                         Reise-Events bearbeiten), 'participant' = einfacher
--                         Mitreisender.
--   EventRelation.role  : 'leader' = Veranstalter eines Standalone-Events
--                         (Reise-Events erben die Rechte von der Reise),
--                         'participant' = einfacher Event-Teilnehmer.
--
-- Bestandsdaten erhalten den Default 'participant'; es wird kein bestehender
-- Teilnehmer automatisch zum Reiseleiter. Datenverlust ist fuer diese
-- Umstellung akzeptiert.
--
-- Zusaetzlich erhaelt TravelAccommodation eine optionale direkte
-- Reisezuordnung (tripId), damit Reiseleiter Unterkuenfte selbst anlegen
-- und der Reise zuordnen koennen. Bestandsunterkuenfte bleiben ueber die
-- bisherige TravelRelation.accommodation-Verknuepfung sichtbar.

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TravelRelation'
);

SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE `TravelRelation`
       ADD COLUMN `role` ENUM(''leader'',''participant'') NOT NULL DEFAULT ''participant'' AFTER `accommodation`,
       ADD KEY `idx_travelrelation_trip_role` (`tripid`,`role`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EventRelation'
);

SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE `EventRelation`
       ADD COLUMN `role` ENUM(''leader'',''participant'') NOT NULL DEFAULT ''participant'' AFTER `userId`,
       ADD KEY `idx_eventrelation_event_role` (`eventId`,`role`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TravelAccommodation'
);

SET @sql := IF(
    @table_exists > 0,
    'ALTER TABLE `TravelAccommodation`
       ADD COLUMN `tripId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `citySlug`,
       ADD KEY `idx_travelaccommodation_trip` (`tripId`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
