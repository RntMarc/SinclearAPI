-- Emoji reactions on stories. Exactly one reaction per user and story;
-- adding another emoji replaces the existing one.
CREATE TABLE IF NOT EXISTS `StoryReaction` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storyId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emoji` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_story_reaction_user` (`storyId`,`userId`),
  KEY `idx_story_reaction_story` (`storyId`),
  CONSTRAINT `fk_story_reaction_story` FOREIGN KEY (`storyId`) REFERENCES `Story` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_story_reaction_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
