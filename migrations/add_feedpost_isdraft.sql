-- Migration: Add isDraft column to FeedPosts
-- Applied: 2026-09-03
-- Author: SinclearAPI Agent

ALTER TABLE `FeedPosts`
  ADD COLUMN `isDraft` tinyint(1) NOT NULL DEFAULT 1
  AFTER `content`;

-- Existing posts are published (isDraft = 0)
UPDATE `FeedPosts` SET `isDraft` = 0;
