-- Public Transport: Deutsche Bahn Integration
-- TravelStop (lokaler Stationen-Cache), Journey, Leg, Participant

CREATE TABLE IF NOT EXISTS `TravelStop` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ril100` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `weight` double DEFAULT NULL,
  `products` json DEFAULT NULL,
  `lastUpdated` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stop_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TravelPublicTransportJourney` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creatorId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `refreshToken` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chosenAt` datetime NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pt_journey_trip` (`tripId`),
  KEY `idx_pt_journey_creator` (`creatorId`),
  CONSTRAINT `fk_pt_journey_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pt_journey_creator` FOREIGN KEY (`creatorId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TravelPublicTransportLeg` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `journeyId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legIndex` tinyint NOT NULL,
  `mode` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lineName` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lineProduct` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `originStopId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destinationStopId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `originStopName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destinationStopName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dbTripId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plannedDeparture` datetime NOT NULL,
  `plannedArrival` datetime NOT NULL,
  `actualDeparture` datetime DEFAULT NULL,
  `actualArrival` datetime DEFAULT NULL,
  `departureDelay` int DEFAULT NULL,
  `arrivalDelay` int DEFAULT NULL,
  `departurePlatform` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrivalPlatform` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('planned','delayed','cancelled','in_transit','arrived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `rawResponse` json DEFAULT NULL,
  `lastCheckedAt` datetime(3) DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL,
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pt_leg_journey` (`journeyId`),
  KEY `idx_pt_leg_tripId` (`dbTripId`),
  KEY `idx_pt_leg_status` (`status`),
  KEY `idx_pt_leg_lastChecked` (`lastCheckedAt`),
  CONSTRAINT `fk_pt_leg_journey` FOREIGN KEY (`journeyId`) REFERENCES `TravelPublicTransportJourney` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_leg_origin` FOREIGN KEY (`originStopId`) REFERENCES `TravelStop` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pt_leg_destination` FOREIGN KEY (`destinationStopId`) REFERENCES `TravelStop` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TravelPublicTransportParticipant` (
  `journeyId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `addedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`journeyId`,`userId`),
  CONSTRAINT `fk_pt_participant_journey` FOREIGN KEY (`journeyId`) REFERENCES `TravelPublicTransportJourney` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_participant_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
