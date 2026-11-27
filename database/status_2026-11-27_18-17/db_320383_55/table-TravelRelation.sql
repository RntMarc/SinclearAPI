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

-- Exportiere Struktur von Tabelle db_320383_55.TravelRelation
CREATE TABLE IF NOT EXISTS `TravelRelation` (
  `ID` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accommodation` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('leader','participant') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'participant',
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uniq_travelrelation_user_trip` (`userid`,`tripid`),
  KEY `idx_travelrelation_trip_role` (`tripid`,`role`),
  CONSTRAINT `fk_travel_relation_trip` FOREIGN KEY (`tripid`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daten-Export vom Benutzer nicht ausgewählt

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
