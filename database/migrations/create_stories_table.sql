-- Migration: Create stories table
-- Stores user stories (images with optional captions).
-- Stories are visible to all users for exactly 7 days after creation
-- (expiresAt is set by the API to createdAt + 7 days).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `Story` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `expiresAt` datetime(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Story`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_story_user_created` (`userId`, `createdAt`),
  ADD KEY `idx_story_expires` (`expiresAt`);

ALTER TABLE `Story`
  ADD CONSTRAINT `fk_story_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
