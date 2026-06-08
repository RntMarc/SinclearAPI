-- Migration: Auth token tables and extended chat tables
-- Run once against the production database

CREATE TABLE IF NOT EXISTS `refresh_token_families` (
    `id` varchar(191) NOT NULL,
    `user_id` varchar(191) NOT NULL,
    `created_at` datetime NOT NULL,
    `revoked_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rtf_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` varchar(191) NOT NULL,
    `family_id` varchar(191) NOT NULL,
    `user_id` varchar(191) NOT NULL,
    `token_hash` varchar(64) NOT NULL,
    `expires_at` datetime NOT NULL,
    `revoked_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_rt_hash` (`token_hash`),
    KEY `idx_rt_family` (`family_id`),
    KEY `idx_rt_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jti_blacklist` (
    `id` varchar(191) NOT NULL,
    `jti` varchar(191) NOT NULL,
    `expires_at` datetime NOT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_jti` (`jti`),
    KEY `idx_jti_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `direct_chats` (
    `id` varchar(191) NOT NULL,
    `user_a_id` varchar(191) NOT NULL,
    `user_b_id` varchar(191) NOT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_direct_pair` (`user_a_id`, `user_b_id`),
    KEY `idx_direct_user_b` (`user_b_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_read_receipts` (
    `id` varchar(191) NOT NULL,
    `user_id` varchar(191) NOT NULL,
    `chat_id` varchar(255) NOT NULL,
    `chat_type` enum('direct','group') NOT NULL,
    `last_read_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_receipt_user_chat` (`user_id`, `chat_id`, `chat_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_presence` (
    `user_id` varchar(191) NOT NULL,
    `status` enum('online','away','offline') NOT NULL DEFAULT 'offline',
    `last_seen_at` datetime NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_devices` (
    `id` varchar(191) NOT NULL,
    `user_id` varchar(191) NOT NULL,
    `device_name` varchar(191) DEFAULT NULL,
    `platform` varchar(50) DEFAULT NULL,
    `push_token` text DEFAULT NULL,
    `last_active_at` datetime NOT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_devices_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sse_events` (
    `id` varchar(191) NOT NULL,
    `user_id` varchar(191) NOT NULL,
    `event_type` varchar(100) NOT NULL,
    `payload` json NOT NULL,
    `delivered_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sse_user_pending` (`user_id`, `delivered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
