-- Migration: Add lifecycle tracking to PushSubscription
-- Enables failure tracking (consecutiveFailures) and stale detection
-- (lastSeenAt/lastSuccessAt) for dead push client cleanup.

ALTER TABLE `PushSubscription`
  ADD COLUMN `lastSeenAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER `createdAt`,
  ADD COLUMN `lastSuccessAt` datetime(3) DEFAULT NULL AFTER `lastSeenAt`,
  ADD COLUMN `lastErrorAt` datetime(3) DEFAULT NULL AFTER `lastSuccessAt`,
  ADD COLUMN `lastError` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `lastErrorAt`,
  ADD COLUMN `consecutiveFailures` int NOT NULL DEFAULT 0 AFTER `lastError`;

ALTER TABLE `PushSubscription`
  ADD KEY `idx_pushsub_last_seen` (`lastSeenAt`),
  ADD KEY `idx_pushsub_failures` (`consecutiveFailures`);
