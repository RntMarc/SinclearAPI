-- LaMetric Time integration: ein Token pro Nutzer (1 Jahr gültig).
-- Der Token wird im Klartext gespeichert, damit der Nutzer ihn später erneut
-- anzeigen lassen und weitere Uhren/Apps verknüpfen kann (geringe Sensitivität,
-- es werden keine Nachrichteninhalte preisgegeben).
--
-- Hinweis: Diese Datei ist NICHT blind rerun-faehig. Vor der Ausfuehrung pruefen,
-- ob Tabelle/Event bereits existieren.

CREATE TABLE IF NOT EXISTS `LaMetricToken` (
  `id` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT 'LaMetric Time',
  `token` varchar(64) NOT NULL,
  `expiresAt` datetime NOT NULL,
  `lastUsedAt` datetime(3) DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_lametric_token_value` (`token`),
  UNIQUE KEY `idx_lametric_token_user` (`userId`),
  KEY `idx_lametric_token_expires` (`expiresAt`),
  CONSTRAINT `fk_lametric_token_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abgelaufene Token wöchentlich entfernen (analog clean_expired_mcp_keys).
CREATE EVENT IF NOT EXISTS `clean_expired_lametric_tokens`
  ON SCHEDULE EVERY 1 WEEK
  ON COMPLETION NOT PRESERVE
  ENABLE
  DO DELETE FROM `LaMetricToken` WHERE `expiresAt` < NOW();
