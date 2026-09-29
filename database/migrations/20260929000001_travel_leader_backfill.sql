-- Fehlende Reiseleiter/Veranstalter nachtragen.
--
-- Die Migration `20260928000000_travel_leader_roles.sql` hat die Rolle
-- eingefuehrt und Bestandsrelationen auf 'participant' gesetzt, ohne jemanden
-- zu befoerdern. Dadurch besitzen vor der Umstellung angelegte Reisen und
-- Standalone-Events keinen Leader mehr; folglich ist `canEdit` fuer alle
-- Nutzer false und weder Reise noch Events/Unterkuenfte sind bearbeitbar.
--
-- Diese Migration befoerdert fuer jede Reise/jedes Standalone-Event ohne
-- Leader die aelteste Relation (UUIDv7 ist zeit-sortierbar, daher MIN(ID))
-- zum Leader. Bewusste Festlegung: falls der falsche Nutzer getroffen wird,
-- kann ein Admin die Rollen im Dashboard korrigieren.

-- 1) Reisen ohne Leader
SET @has_role := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelRelation'
      AND COLUMN_NAME = 'role'
);

SET @sql := IF(
    @has_role > 0,
    'UPDATE `TravelRelation` r
       JOIN (
           SELECT `tripid`, MIN(`ID`) AS keepId
           FROM `TravelRelation`
           GROUP BY `tripid`
           HAVING SUM(`role` = ''leader'') = 0
       ) x ON x.keepId = r.ID
       SET r.`role` = ''leader''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Standalone-Events ohne Veranstalter
SET @has_event_role := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'EventRelation'
      AND COLUMN_NAME = 'role'
);

SET @sql := IF(
    @has_event_role > 0,
    'UPDATE `EventRelation` r
       JOIN (
           SELECT `eventId`, MIN(`ID`) AS keepId
           FROM `EventRelation`
           GROUP BY `eventId`
           HAVING SUM(`role` = ''leader'') = 0
       ) x ON x.keepId = r.ID
       SET r.`role` = ''leader''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
