-- Migration: Add dedupeKey to Notification table
-- Used for coalesced chat notifications (one notification per conversation).

ALTER TABLE `Notification`
  ADD COLUMN `dedupeKey` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `type`;

ALTER TABLE `Notification`
  ADD KEY `idx_notification_dedupe` (`userId`, `dedupeKey`, `isRead`);
