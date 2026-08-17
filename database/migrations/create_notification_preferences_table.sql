-- Migration: Create notification preferences table
-- Stores per-user, per-notification-type preferences (enabled / disabled / custom).
-- custom = denylist: the IDs in `data` are excluded from delivery.
-- No row means the default: enabled.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `NotificationPreference` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled' COMMENT 'enabled, disabled or custom (denylist)',
  `data` json DEFAULT NULL COMMENT 'Denylist when state = custom (e.g. {"forumIds":[]} or {"userIds":[]}), otherwise null',
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `NotificationPreference`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_notifpref_user_type` (`userId`, `type`);

ALTER TABLE `NotificationPreference`
  ADD CONSTRAINT `fk_notifpref_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
