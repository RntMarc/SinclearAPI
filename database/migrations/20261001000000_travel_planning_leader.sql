-- Reiseplanung, Phase 2 (API und Rechte): Leitung der Planung.
--
-- Additive Migration, rerun-sicher, kein Datenverlust:
--   * `TravelPlanMember` erhaelt eine Rolle `role` ('leader' oder 'member').
--     Bestehende Planungsmitglieder erhalten per Default 'member'. Die
--     Leitung ("Leitung entscheidet") wird ausschliesslich hier gefuehrt und
--     NICHT ueber TravelRelation, damit Personen, die nur mitplanen, keinen
--     Zugriff auf operative Reise-Objekte (Events, Tickets, Unterkuenfte)
--     erhalten. Der Ersteller einer Planungsreise wird im Service als
--     'leader' eingetragen; die Letzter-Leader-Invariante wird im Service
--     durchgesetzt (wie bei TravelRelation/EventRelation).
--
-- Hinweis: Der Dateiname ist zeitlich sortierbar. Es gibt keinen lokalen
-- Migration-Runner; die Datei wird vom Betreiber auf dem Server angewendet.

-- 1) TravelPlanMember.role
--    Default 'member' => bestehende Eintraege bleiben einfache Mitglieder.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelPlanMember'
      AND COLUMN_NAME = 'role'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `TravelPlanMember`
       ADD COLUMN `role` enum(''leader'',''member'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''member'' AFTER `status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
