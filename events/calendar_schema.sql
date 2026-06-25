-- Calendar-Schema: Kalender-Events mit Teilnehmern und Sichtbarkeit
-- ACHTUNG: Die Tabelle heisst `CalendarEvent` um Verwechslung mit `TravelEvent` zu vermeiden.
--
-- Migration von der alten `Event`-Tabelle:
--   `Event`            → `CalendarEvent`     (neue Struktur mit visibility statt isPublic)
--   `EventPermission`  → `CalendarEventParticipant`  (vereinfacht, nur userId + eventId)
-- Die alten Tabellen werden gelöscht, bestehende Daten gehen verloren.

DROP TABLE IF EXISTS `EventPermission`;
DROP TABLE IF EXISTS `Event`;

CREATE TABLE IF NOT EXISTS `CalendarEvent` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `creatorId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `startTime` datetime NOT NULL,
  `endTime` datetime NOT NULL,
  `visibility` tinyint(1) NOT NULL DEFAULT 0,
  `createdAt` datetime(3) NOT NULL,
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_calendar_creator` (`creatorId`),
  KEY `idx_calendar_time` (`startTime`, `endTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CalendarEventParticipant` (
  `eventId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `addedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`eventId`, `userId`),
  KEY `idx_calendar_participant_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
