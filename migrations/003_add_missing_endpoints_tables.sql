-- Migration: Add missing tables for user preferences, contact info, social info, and close friends.

CREATE TABLE IF NOT EXISTS `UserPreferences` (
    `id` varchar(191) NOT NULL,
    `userId` varchar(191) NOT NULL,
    `theme` varchar(50) DEFAULT 'system',
    `language` varchar(10) DEFAULT 'de',
    `primaryColor` varchar(20) DEFAULT NULL,
    `timezone` varchar(100) DEFAULT 'Europe/Berlin',
    `updatedAt` datetime NOT NULL,
    `createdAt` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_up_user` (`userId`),
    CONSTRAINT `fk_up_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ContactInfo` (
    `id` varchar(191) NOT NULL,
    `userId` varchar(191) NOT NULL,
    `phone` varchar(50) DEFAULT NULL,
    `address` text DEFAULT NULL,
    `updatedAt` datetime NOT NULL,
    `createdAt` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ci_user` (`userId`),
    CONSTRAINT `fk_ci_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SocialInfo` (
    `id` varchar(191) NOT NULL,
    `userId` varchar(191) NOT NULL,
    `twitter` varchar(191) DEFAULT NULL,
    `instagram` varchar(191) DEFAULT NULL,
    `linkedin` varchar(191) DEFAULT NULL,
    `github` varchar(191) DEFAULT NULL,
    `updatedAt` datetime NOT NULL,
    `createdAt` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_si_user` (`userId`),
    CONSTRAINT `fk_si_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CloseFriend` (
    `id` varchar(191) NOT NULL,
    `userId` varchar(191) NOT NULL,
    `friendId` varchar(191) NOT NULL,
    `createdAt` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_cf_pair` (`userId`, `friendId`),
    KEY `idx_cf_friend` (`friendId`),
    CONSTRAINT `fk_cf_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cf_friend` FOREIGN KEY (`friendId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Add image column to User if it does not exist (it's required for profile picture handling)
-- Using a procedure to safely add the column if it's missing.
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS add_image_to_user()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = 'User'
        AND column_name = 'image'
    ) THEN
        ALTER TABLE `User` ADD COLUMN `image` LONGTEXT DEFAULT NULL;
    END IF;
END //
DELIMITER ;
CALL add_image_to_user();
DROP PROCEDURE add_image_to_user;
