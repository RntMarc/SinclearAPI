/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE IF NOT EXISTS `PtJourney` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creatorId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fromStationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fromStationName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `toStationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `toStationName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departureTime` datetime NOT NULL,
  `arrivalTime` datetime NOT NULL,
  `duration` int NOT NULL,
  `transfers` int DEFAULT '0',
  `chosenAt` datetime NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pt_journey_creator` (`creatorId`),
  KEY `idx_pt_journey_trip` (`tripId`),
  KEY `idx_pt_journey_departure` (`departureTime`),
  CONSTRAINT `fk_pt_journey_creator` FOREIGN KEY (`creatorId`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_journey_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
