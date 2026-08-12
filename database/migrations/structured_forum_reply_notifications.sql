-- Migration: Structured notification payloads
-- Keeps legacy title/body columns for backwards-compatible storage, but allows
-- clients to rely exclusively on type + data for generated display text/routes.

ALTER TABLE `Notification`
  MODIFY `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  MODIFY `body` text COLLATE utf8mb4_unicode_ci NOT NULL;

UPDATE `Notification`
SET `title` = '', `body` = ''
WHERE `type` = 'forum_reply';
