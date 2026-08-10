-- Migration: Create push_subscriptions table
-- Stores Web Push (VAPID) and UnifiedPush subscriptions per user.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `PushSubscription` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'webpush or unifiedpush',
  `endpoint` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `p256dh` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userAgent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `PushSubscription`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_pushsub_endpoint` (`endpoint`(255)),
  ADD KEY `idx_pushsub_user` (`userId`);

ALTER TABLE `PushSubscription`
  ADD CONSTRAINT `fk_pushsub_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
