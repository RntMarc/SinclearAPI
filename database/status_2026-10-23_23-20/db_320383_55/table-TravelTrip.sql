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

-- Exportiere Struktur von Tabelle db_320383_55.TravelTrip
CREATE TABLE IF NOT EXISTS `TravelTrip` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `startDate` date DEFAULT NULL,
  `endDate` date DEFAULT NULL,
  `allDay` tinyint(1) NOT NULL DEFAULT '1',
  `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin',
  `startAt` datetime(3) DEFAULT NULL,
  `endAt` datetime(3) DEFAULT NULL,
  `hastickets` enum('1','0') COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `ticket` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ticketUrl` text COLLATE utf8mb4_unicode_ci,
  `forumId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` enum('planning','active','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_trip_forum` (`forumId`),
  KEY `idx_trip_instant` (`startAt`,`endAt`),
  KEY `idx_trip_day` (`startDate`,`endDate`),
  CONSTRAINT `TravelTrip_ibfk_1` FOREIGN KEY (`forumId`) REFERENCES `Forum` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daten-Export vom Benutzer nicht ausgewählt

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
