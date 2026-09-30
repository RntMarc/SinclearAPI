-- Planungsreisen: Zustand, Planungsteilnehmer, Phasenthemen und
-- Planungsdaten (Termin-, Transport-, Unterkunfts- und Eventvorschlaege).
--
-- Additive Migration, rerun-sicher, kein Datenverlust:
--   * TravelTrip erhaelt einen Zustand `state` ('planning', 'active',
--     'cancelled'). Bestehende Reisen erhalten per Default 'active' und
--     bleiben unveraendert nutzbar. 'cancelled' ist fuer eine spaetere
--     Storno-/Archivlogik vorgesehen, Phase 1 nutzt es nicht.
--   * TravelAccommodationTrip erhaelt den vereinbarten Unterkunftspreis
--     (`pricePerPersonPerNight`, `currency`). Der Preis ist reise-spezifisch
--     und wird erst bei der Aktivierung (Phase 4) aus der gewaehlten
--     Planungsoption uebernommen; der globale Katalog bleibt preisfrei.
--   * Neue Tabellen `TravelPlan*` trennen Planungsteilnehmer, Phasenstatus
--     und Planungsvorschlaege strikt von den operativen Travel-Objekten.
--
-- Hinweis: Der Dateiname ist zeitlich sortierbar. Es gibt keinen lokalen
-- Migration-Runner; die Datei wird vom Betreiber auf dem Server angewendet.

-- 1) TravelTrip.state
--    Default 'active' => alle Bestandsreisen bleiben operativ.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelTrip'
      AND COLUMN_NAME = 'state'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `TravelTrip`
       ADD COLUMN `state` enum(''planning'',''active'',''cancelled'') NOT NULL DEFAULT ''active'' AFTER `forumId`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) TravelAccommodationTrip.pricePerPersonPerNight
--    Bewusst getrennte Existenzpruefungen je Spalte, damit auch ein
--    partiell angewandter Zustand sauber abgedeckt ist.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelAccommodationTrip'
      AND COLUMN_NAME = 'pricePerPersonPerNight'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `TravelAccommodationTrip`
       ADD COLUMN `pricePerPersonPerNight` decimal(10,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) TravelAccommodationTrip.currency
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelAccommodationTrip'
      AND COLUMN_NAME = 'currency'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `TravelAccommodationTrip`
       ADD COLUMN `currency` char(3) DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Planungsteilnehmer (getrennt von TravelRelation)
--    `status`: invited = eingeladen, accepted/declined = Rueckmeldung,
--    inactive = deaktiviert (Zugriff entzogen, Historie bleibt).
--    `origin` haelt optional die Herkunft der Mitgliedschaft fest.
--    `deactivatedAt` macht die Deaktivierung nachvollziehbar.
CREATE TABLE IF NOT EXISTS `TravelPlanMember` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `status` enum('invited','accepted','declined','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `origin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `deactivatedAt` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_planmember_trip_user` (`tripId`,`userId`),
  KEY `idx_planmember_user` (`userId`),
  CONSTRAINT `fk_planmember_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planmember_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Phasenthemen (serverseitig fest) mit persistiertem Status
--    participants = Datum und Teilnehmende
--    travel       = Anreise, Abreise und Unterkunft
--    program      = Tagesprogramm und Events
CREATE TABLE IF NOT EXISTS `TravelPlanTopic` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `topic` enum('participants','travel','program') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','in_progress','completed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plantopic_trip_topic` (`tripId`,`topic`),
  CONSTRAINT `fk_plantopic_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Terminoptionen (zeitzonenbewusst wie PollOption)
--    `isFinal` markiert die von der Leitung festgelegte Option (Service
--    stellt sicher, dass hoechstens eine Option je Reise `isFinal` traegt).
CREATE TABLE IF NOT EXISTS `TravelPlanDateOption` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allDay` tinyint NOT NULL DEFAULT '1',
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin',
  `startAt` datetime(3) DEFAULT NULL,
  `endAt` datetime(3) DEFAULT NULL,
  `startDate` date DEFAULT NULL,
  `endDate` date DEFAULT NULL,
  `isFinal` tinyint NOT NULL DEFAULT '0',
  `proposedBy` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` smallint NOT NULL DEFAULT '0',
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_plandateoption_trip` (`tripId`),
  KEY `idx_plandateoption_proposer` (`proposedBy`),
  CONSTRAINT `fk_plandateoption_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plandateoption_proposer` FOREIGN KEY (`proposedBy`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Rueckmeldung je Terminoption und aktivem Planungsmitglied
CREATE TABLE IF NOT EXISTS `TravelPlanDateResponse` (
  `id` varchar(191) NOT NULL,
  `dateOptionId` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `availability` enum('yes','maybe','no') COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plandateresponse_option_user` (`dateOptionId`,`userId`),
  KEY `idx_plandateresponse_user` (`userId`),
  CONSTRAINT `fk_plandateresponse_option` FOREIGN KEY (`dateOptionId`) REFERENCES `TravelPlanDateOption` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plandateresponse_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Transportpraeferenz je Mitglied und Richtung (An-/Abreise).
--    `mode` bewusst als freier String (offene Verkehrsmittelwahl);
--    `offersRide`/`availableSeats` bilden das Mitfahrangebot ab.
CREATE TABLE IF NOT EXISTS `TravelPlanTransport` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `direction` enum('outbound','return') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'outbound',
  `mode` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offersRide` tinyint NOT NULL DEFAULT '0',
  `availableSeats` smallint DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plantransport_trip_user_dir` (`tripId`,`userId`,`direction`),
  KEY `idx_plantransport_user` (`userId`),
  CONSTRAINT `fk_plantransport_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plantransport_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Unterkunftsoptionen inkl. Preis pro Person und Nacht.
--    Optionaler Verweis auf den globalen Katalog (`accommodationId`);
--    Inline-Felder fuer OSM-/Adressdaten ohne Katalogeintrag.
--    `isSelected` markiert die von der Leitung gewaehlte Option.
CREATE TABLE IF NOT EXISTS `TravelPlanAccommodationOption` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `accommodationId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `address` text COLLATE utf8mb4_unicode_ci,
  `OSMID` bigint unsigned DEFAULT NULL,
  `latitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `citySlug` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pricePerPersonPerNight` decimal(10,2) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `isSelected` tinyint NOT NULL DEFAULT '0',
  `proposedBy` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_planacco_trip` (`tripId`),
  KEY `idx_planacco_accommodation` (`accommodationId`),
  KEY `idx_planacco_proposer` (`proposedBy`),
  CONSTRAINT `fk_planacco_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planacco_accommodation` FOREIGN KEY (`accommodationId`) REFERENCES `TravelAccommodation` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `fk_planacco_proposer` FOREIGN KEY (`proposedBy`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) Tagesprogramm-Vorschlaege ("Planungs-Events").
--     `isConfirmed` markiert die von der Leitung bestaetigten Vorschlaege;
--     `confirmedEventId` verweist nach der Aktivierung auf das erzeugte
--     TravelEvent (Idempotenz/Nachvollziehbarkeit, bewusst ohne FK).
CREATE TABLE IF NOT EXISTS `TravelPlanEvent` (
  `id` varchar(191) NOT NULL,
  `tripId` varchar(191) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `dayIndex` smallint NOT NULL DEFAULT '0',
  `allDay` tinyint NOT NULL DEFAULT '0',
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin',
  `startAt` datetime(3) DEFAULT NULL,
  `endAt` datetime(3) DEFAULT NULL,
  `startDate` date DEFAULT NULL,
  `endDate` date DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `latitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `OSMID` bigint DEFAULT NULL,
  `citySlug` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isConfirmed` tinyint NOT NULL DEFAULT '0',
  `confirmedEventId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proposedBy` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_planevent_trip` (`tripId`),
  KEY `idx_planevent_proposer` (`proposedBy`),
  CONSTRAINT `fk_planevent_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planevent_proposer` FOREIGN KEY (`proposedBy`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11) Teilnahmeinteresse an Tagesprogramm-Vorschlaegen
CREATE TABLE IF NOT EXISTS `TravelPlanEventInterest` (
  `id` varchar(191) NOT NULL,
  `eventSuggestionId` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `interest` enum('yes','maybe','no') COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_planeventinterest_sugg_user` (`eventSuggestionId`,`userId`),
  KEY `idx_planeventinterest_user` (`userId`),
  CONSTRAINT `fk_planeventinterest_sugg` FOREIGN KEY (`eventSuggestionId`) REFERENCES `TravelPlanEvent` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planeventinterest_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
