-- Zeitzonen-bewusste Zeitpunkte fuer Kalender-Events, Reise-Events und Reisen.
--
-- Neues Modell (Branchenstandard, vgl. Google Calendar / RFC 5545 / RFC 3339):
--   * Getaktet (allDay = 0): startAt/endAt als UTC-Instant in DATETIME(3),
--     zusaetzlich timezone (IANA) fuer die gemeinte Wandzeit.
--   * Ganztägig (allDay = 1): startDate/endDate als ziviler Tag (DATE,
--     inklusives Ende), zusaetzlich timezone.
--   * Die Spalten startTime/endTime entfallen.
--
-- Hinweis: Diese Datei ist NICHT blind rerun-faehig. Vor der Ausfuehrung
-- pruefen, ob die Spalten bereits umgestellt sind. Bestehende Uhrzeiten gehen
-- verloren (Datenverlust ist fuer diese Umstellung akzeptiert).

ALTER TABLE `CalendarEvent`
  MODIFY COLUMN `startDate` date DEFAULT NULL,
  MODIFY COLUMN `endDate` date DEFAULT NULL,
  DROP COLUMN `startTime`,
  DROP COLUMN `endTime`,
  ADD COLUMN `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin' AFTER `allDay`,
  ADD COLUMN `startAt` datetime(3) DEFAULT NULL AFTER `timezone`,
  ADD COLUMN `endAt` datetime(3) DEFAULT NULL AFTER `startAt`,
  DROP KEY `idx_calendar_time`,
  ADD KEY `idx_calendar_instant` (`startAt`, `endAt`),
  ADD KEY `idx_calendar_day` (`startDate`, `endDate`);

ALTER TABLE `TravelEvent`
  MODIFY COLUMN `startDate` date DEFAULT NULL,
  MODIFY COLUMN `endDate` date DEFAULT NULL,
  DROP COLUMN `startTime`,
  DROP COLUMN `endTime`,
  ADD COLUMN `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin' AFTER `allDay`,
  ADD COLUMN `startAt` datetime(3) DEFAULT NULL AFTER `timezone`,
  ADD COLUMN `endAt` datetime(3) DEFAULT NULL AFTER `startAt`,
  DROP KEY `idx_travel_event_time`,
  ADD KEY `idx_travel_event_instant` (`startAt`, `endAt`),
  ADD KEY `idx_travel_event_day` (`startDate`, `endDate`);

ALTER TABLE `TravelTrip`
  MODIFY COLUMN `startDate` date DEFAULT NULL,
  MODIFY COLUMN `endDate` date DEFAULT NULL,
  DROP COLUMN `startTime`,
  DROP COLUMN `endTime`,
  ADD COLUMN `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin' AFTER `allDay`,
  ADD COLUMN `startAt` datetime(3) DEFAULT NULL AFTER `timezone`,
  ADD COLUMN `endAt` datetime(3) DEFAULT NULL AFTER `startAt`,
  DROP KEY `idx_trip_time`,
  ADD KEY `idx_trip_instant` (`startAt`, `endAt`),
  ADD KEY `idx_trip_day` (`startDate`, `endDate`);
