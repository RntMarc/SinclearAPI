-- UserActivity: Speichert den letzten Aktivitätszeitstempel pro Nutzer.
-- Aktualisiert via UserActivityMiddleware bei relevanten API-Anfragen.
-- Dient als Fallback für Presence-Abfragen wenn Centrifugo offline ist.
CREATE TABLE IF NOT EXISTS `UserActivity` (
  `userId` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastActiveAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `lastActiveEndpoint` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`userId`),
  CONSTRAINT `fk_user_activity_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
