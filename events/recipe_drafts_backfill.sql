-- Backfill für Datenbanken, auf denen recipe_drafts.sql bereits ausgeführt wurde
-- (dort wurden alle Bestandsrezepte durch DEFAULT 1 fälschlich zu Entwürfen).
--
-- Hinweis: Vorher prüfen, ob seit dem Draft-Update echte Entwürfe (via MCP)
-- angelegt wurden. Diese würden mit veröffentlicht. Optional auf Bestand
-- begrenzen, z.B.:
--   UPDATE `Recipe` SET `isDraft` = 0 WHERE `isDraft` = 1 AND `createdAt` < '2026-08-03 18:00:00';

UPDATE `Recipe` SET `isDraft` = 0 WHERE `isDraft` = 1;
