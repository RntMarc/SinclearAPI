-- Migration: Create notifications table
-- Stores in-app notifications for all users (polling + push delivery).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `Notification` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `isRead` tinyint(1) NOT NULL DEFAULT '0',
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Notification`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notification_user_read_created` (`userId`, `isRead`, `createdAt`);

ALTER TABLE `Notification`
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
