-- --------------------------------------------------------
-- Host:                         mmrtk.lima-db.de
-- Server-Version:               8.4.10-10 - Percona Server (GPL), Release '10', Revision 'd76e81f4'
-- Server-Betriebssystem:        Linux
-- HeidiSQL Version:             12.21.1.1
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Exportiere Struktur von Tabelle db_320383_55.TravelPlanDateOption
CREATE TABLE IF NOT EXISTS `TravelPlanDateOption` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allDay` tinyint NOT NULL DEFAULT '1',
  `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin',
  `startAt` datetime(3) DEFAULT NULL,
  `endAt` datetime(3) DEFAULT NULL,
  `startDate` date DEFAULT NULL,
  `endDate` date DEFAULT NULL,
  `isFinal` tinyint NOT NULL DEFAULT '0',
  `proposedBy` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` smallint NOT NULL DEFAULT '0',
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_plandateoption_trip` (`tripId`),
  KEY `idx_plandateoption_proposer` (`proposedBy`),
  CONSTRAINT `fk_plandateoption_proposer` FOREIGN KEY (`proposedBy`) REFERENCES `User` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_plandateoption_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daten-Export vom Benutzer nicht ausgewählt

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
