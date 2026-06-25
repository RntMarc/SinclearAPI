-- Notification-Schema v2: code + payload statt type + entityId
-- ACHTUNG: Bricht Kompatibilität zu alten Clients! Alle bestehenden Daten gehen verloren.

DROP TABLE IF EXISTS `Notification`;
CREATE TABLE `Notification` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_user_time` (`userId`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
