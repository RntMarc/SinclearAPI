ALTER TABLE `Recipe`
  ADD COLUMN `isDraft` tinyint(1) NOT NULL DEFAULT 1 AFTER `servings`;
