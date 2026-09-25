/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE IF NOT EXISTS `UserPreferences` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'de',
  `theme` enum('light','dark') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dark',
  `primaryColor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'var(--primary)',
  `timezone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `emailVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `birthdayVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `syncAvatarFromDiscord` tinyint(1) NOT NULL DEFAULT '1',
  `onboardingCompleted` tinyint(1) NOT NULL DEFAULT '0',
  `discordVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `fluxerVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `matrixVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `signalVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `whatsappVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `unsplashVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `instagramVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `mastodonVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `pixelfedVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `blueskyVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `youtubeVisibility` tinyint(1) NOT NULL DEFAULT '1',
  `twitchVisibility` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_userpreferences_userid` (`userId`),
  CONSTRAINT `fk_userpreferences_userid` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
