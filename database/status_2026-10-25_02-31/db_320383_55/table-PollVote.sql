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

-- Exportiere Struktur von Tabelle db_320383_55.PollVote
CREATE TABLE IF NOT EXISTS `PollVote` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pollId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optionId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `participantHash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_poll_hash_option` (`pollId`,`participantHash`,`optionId`),
  KEY `idx_vote_poll_option` (`pollId`,`optionId`),
  KEY `idx_vote_hash` (`pollId`,`participantHash`),
  KEY `fk_pollvote_option` (`optionId`),
  CONSTRAINT `fk_pollvote_option` FOREIGN KEY (`optionId`) REFERENCES `PollOption` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pollvote_poll` FOREIGN KEY (`pollId`) REFERENCES `Poll` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daten-Export vom Benutzer nicht ausgewählt

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
