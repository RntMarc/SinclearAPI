-- Event Date/Time Split: Ersetzt datetime-Spalten durch getrennte DATE/TIME + allDay-Flag
-- Betrifft: CalendarEvent, TravelTrip, TravelEvent
--
-- ============================================================================
-- WICHTIG: Diese Datei ist NICHT blind rerun-faehig.
-- MySQL kennt kein "DROP COLUMN IF EXISTS". Wurde ein Block bereits
-- ausgefuehrt, schlaegt er beim erneuten Lauf fehl (Duplicate column /
-- Can't DROP / Duplicate key name). Vor der Ausfuehrung daher den
-- Ist-Zustand pruefen und bereits migrierte Bloecke ueberspringen:
--
--   SHOW COLUMNS FROM `CalendarEvent`;
--   SHOW COLUMNS FROM `TravelTrip`;
--   SHOW COLUMNS FROM `TravelEvent`;
--   SHOW INDEX  FROM `CalendarEvent`;
--
-- Alt-Schema  = startTime/endTime (datetime) bzw. start/end vorhanden,
--               kein startDate -> Block ausfuehren.
-- Neu-Schema  = startDate/endDate (date) + startTime/endTime (time) +
--               allDay vorhanden -> Block ueberspringen.
--
-- Die DELETEs weiter unten sind absichtlich idempotent (loeschen 0 Zeilen,
-- wenn bereits leer) und koennen gefahrlos erneut laufen.
-- ============================================================================
--
-- Standard-Konvention: allDay=1 fuer TravelTrip (Reisen sind konzeptionell
-- ganztagig), allDay=0 fuer CalendarEvent/TravelEvent.
--
-- Datenverlust ist freigegeben: Die Tabellen werden vor dem Umbau geleert.
-- DELETE statt TRUNCATE, damit Foreign Keys (z. B. TravelTrip.forumId ->
-- Forum) das Leeren nie blockieren.

-- ---------------------------------------------------------------------------
-- 0. Tabellen leeren (idempotent, vor allen ALTERs)
-- ---------------------------------------------------------------------------
-- CalendarEventParticipant wird mitgeleert, da es ausschliesslich
-- Event-Referenzen haelt und sonst verwaisen wuerde.
DELETE FROM `CalendarEventParticipant`;
DELETE FROM `CalendarEvent`;
DELETE FROM `TravelTrip`;
DELETE FROM `TravelEvent`;

-- ---------------------------------------------------------------------------
-- 1. CalendarEvent (nur ausfuehren, wenn Schritt-Diagnose Alt-Schema zeigt)
-- ---------------------------------------------------------------------------
ALTER TABLE `CalendarEvent`
  DROP COLUMN `startTime`,
  DROP COLUMN `endTime`,
  DROP INDEX `idx_calendar_time`,
  ADD COLUMN `startDate` DATE NOT NULL AFTER `description`,
  ADD COLUMN `endDate` DATE NOT NULL AFTER `startDate`,
  ADD COLUMN `startTime` TIME NULL AFTER `endDate`,
  ADD COLUMN `endTime` TIME NULL AFTER `startTime`,
  ADD COLUMN `allDay` TINYINT(1) NOT NULL DEFAULT 0 AFTER `endTime`,
  ADD INDEX `idx_calendar_time` (`startDate`, `endDate`);

-- ---------------------------------------------------------------------------
-- 2. TravelTrip (nur ausfuehren, wenn Schritt-Diagnose Alt-Schema zeigt)
-- ---------------------------------------------------------------------------
ALTER TABLE `TravelTrip`
  DROP COLUMN `start`,
  DROP COLUMN `end`,
  ADD COLUMN `startDate` DATE NOT NULL AFTER `description`,
  ADD COLUMN `endDate` DATE NOT NULL AFTER `startDate`,
  ADD COLUMN `startTime` TIME NULL AFTER `endDate`,
  ADD COLUMN `endTime` TIME NULL AFTER `startTime`,
  ADD COLUMN `allDay` TINYINT(1) NOT NULL DEFAULT 1 AFTER `endTime`,
  ADD INDEX `idx_trip_time` (`startDate`, `endDate`);

-- ---------------------------------------------------------------------------
-- 3. TravelEvent (nur ausfuehren, wenn Schritt-Diagnose Alt-Schema zeigt)
-- ---------------------------------------------------------------------------
ALTER TABLE `TravelEvent`
  DROP COLUMN `start`,
  DROP COLUMN `end`,
  ADD COLUMN `startDate` DATE NOT NULL AFTER `description`,
  ADD COLUMN `endDate` DATE NOT NULL AFTER `startDate`,
  ADD COLUMN `startTime` TIME NULL AFTER `endDate`,
  ADD COLUMN `endTime` TIME NULL AFTER `startTime`,
  ADD COLUMN `allDay` TINYINT(1) NOT NULL DEFAULT 0 AFTER `endTime`,
  ADD INDEX `idx_travel_event_time` (`startDate`, `endDate`);
