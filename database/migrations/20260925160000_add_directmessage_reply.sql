-- Chat-Antworten: eine Nachricht kann auf eine andere Nachricht derselben
-- Konversation verweisen (Zitat).
--
-- Hinweis: Diese Datei ist NICHT blind rerun-faehig. Vor der Ausfuehrung pruefen,
-- ob die Spalte bereits existiert.
--
-- ON DELETE SET NULL: Werden alte Nachrichten per Cron hart geloescht, bleibt
-- die Antwort erhalten und verliert nur den Zitat-Verweis (formatMessage liefert
-- dann replyTo = null). Soft-Delete (deletedAt) laesst den Verweis bestehen; das
-- Zitat wird als "geloescht" dargestellt.

ALTER TABLE `DirectMessage`
  ADD COLUMN `replyToMessageId` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `clientId`,
  ADD KEY `idx_dm_reply_to` (`replyToMessageId`),
  ADD CONSTRAINT `fk_dm_reply_to` FOREIGN KEY (`replyToMessageId`) REFERENCES `DirectMessage` (`id`) ON DELETE SET NULL;
