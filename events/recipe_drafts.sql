ALTER TABLE `Recipe`
  ADD COLUMN `isDraft` tinyint(1) NOT NULL DEFAULT 1 AFTER `servings`;

-- Bestehende Rezepte waren keine Entwürfe: sonst verschwinden alle alten Rezepte aus der öffentlichen Liste.
UPDATE `Recipe` SET `isDraft` = 0;
