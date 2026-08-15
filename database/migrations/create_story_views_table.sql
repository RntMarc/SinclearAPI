-- Migration: Create story views table
-- Tracks which user has viewed which story (at most one row per story/user pair).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `StoryView` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storyId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `StoryView`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_story_view_story_user` (`storyId`, `userId`),
  ADD KEY `idx_story_view_user` (`userId`);

ALTER TABLE `StoryView`
  ADD CONSTRAINT `fk_story_view_story` FOREIGN KEY (`storyId`) REFERENCES `Story` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_story_view_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
