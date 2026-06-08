-- Migration: Rename tables and columns from snake_case to PascalCase/camelCase
-- to match existing database naming convention.
-- Only run if tables are still empty (no data yet).

-- 1. RefreshTokenFamily (was: refresh_token_families)
ALTER TABLE `refresh_token_families` RENAME TO `RefreshTokenFamily`;
ALTER TABLE `RefreshTokenFamily`
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL,
    CHANGE COLUMN `revoked_at` `revokedAt` datetime DEFAULT NULL;
ALTER TABLE `RefreshTokenFamily` DROP INDEX `idx_rtf_user`, ADD INDEX `idx_rtf_user` (`userId`);

-- 2. RefreshToken (was: refresh_tokens)
ALTER TABLE `refresh_tokens` RENAME TO `RefreshToken`;
ALTER TABLE `RefreshToken`
    CHANGE COLUMN `family_id` `familyId` varchar(191) NOT NULL,
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `token_hash` `tokenHash` varchar(64) NOT NULL,
    CHANGE COLUMN `expires_at` `expiresAt` datetime NOT NULL,
    CHANGE COLUMN `revoked_at` `revokedAt` datetime DEFAULT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL;
ALTER TABLE `RefreshToken`
    DROP INDEX `idx_rt_hash`, ADD UNIQUE INDEX `idx_rt_hash` (`tokenHash`),
    DROP INDEX `idx_rt_family`, ADD INDEX `idx_rt_family` (`familyId`),
    DROP INDEX `idx_rt_user`, ADD INDEX `idx_rt_user` (`userId`);

-- 3. JtiBlacklist (was: jti_blacklist)
ALTER TABLE `jti_blacklist` RENAME TO `JtiBlacklist`;
ALTER TABLE `JtiBlacklist`
    CHANGE COLUMN `expires_at` `expiresAt` datetime NOT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL;
ALTER TABLE `JtiBlacklist` DROP INDEX `idx_jti_expires`, ADD INDEX `idx_jti_expires` (`expiresAt`);

-- 4. DirectChat (was: direct_chats)
ALTER TABLE `direct_chats` RENAME TO `DirectChat`;
ALTER TABLE `DirectChat`
    CHANGE COLUMN `user_a_id` `userAId` varchar(191) NOT NULL,
    CHANGE COLUMN `user_b_id` `userBId` varchar(191) NOT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL;
ALTER TABLE `DirectChat`
    DROP INDEX `idx_direct_pair`, ADD UNIQUE INDEX `idx_direct_pair` (`userAId`, `userBId`),
    DROP INDEX `idx_direct_user_b`, ADD INDEX `idx_direct_user_b` (`userBId`);

-- 5. ChatReadReceipt (was: chat_read_receipts)
ALTER TABLE `chat_read_receipts` RENAME TO `ChatReadReceipt`;
ALTER TABLE `ChatReadReceipt`
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `chat_id` `chatId` varchar(255) NOT NULL,
    CHANGE COLUMN `chat_type` `chatType` enum('direct','group') NOT NULL,
    CHANGE COLUMN `last_read_at` `lastReadAt` datetime NOT NULL;
ALTER TABLE `ChatReadReceipt`
    DROP INDEX `idx_receipt_user_chat`, ADD UNIQUE INDEX `idx_receipt_user_chat` (`userId`, `chatId`, `chatType`);

-- 6. UserPresence (was: user_presence)
ALTER TABLE `user_presence` RENAME TO `UserPresence`;
ALTER TABLE `UserPresence`
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `last_seen_at` `lastSeenAt` datetime NOT NULL;

-- 7. UserDevice (was: user_devices)
ALTER TABLE `user_devices` RENAME TO `UserDevice`;
ALTER TABLE `UserDevice`
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `device_name` `deviceName` varchar(191) DEFAULT NULL,
    CHANGE COLUMN `push_token` `pushToken` text DEFAULT NULL,
    CHANGE COLUMN `last_active_at` `lastActiveAt` datetime NOT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL;
ALTER TABLE `UserDevice` DROP INDEX `idx_devices_user`, ADD INDEX `idx_devices_user` (`userId`);

-- 8. SseEvent (was: sse_events)
ALTER TABLE `sse_events` RENAME TO `SseEvent`;
ALTER TABLE `SseEvent`
    CHANGE COLUMN `user_id` `userId` varchar(191) NOT NULL,
    CHANGE COLUMN `event_type` `eventType` varchar(100) NOT NULL,
    CHANGE COLUMN `delivered_at` `deliveredAt` datetime DEFAULT NULL,
    CHANGE COLUMN `created_at` `createdAt` datetime NOT NULL;
ALTER TABLE `SseEvent` DROP INDEX `idx_sse_user_pending`, ADD INDEX `idx_sse_user_pending` (`userId`, `deliveredAt`);
