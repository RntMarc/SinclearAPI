-- Location Sharing: expiration optional machen + Auto-Cleanup nach 7 Tagen Inaktivität
--
-- 1. durationSeconds + expiresAt NULL erlauben (NULL = unbegrenzt, Session läuft nie ab)
-- 2. Bestehende Sessions mit duration behalten ihre expiresAt
-- 3. MySQL Event: Löscht Sessions ohne Aktivität seit > 7 Tagen

-- 1. durationSeconds NULL erlauben (für unbegrenzte Sessions)
ALTER TABLE `LocationSharingSession`
  MODIFY COLUMN `durationSeconds` int DEFAULT NULL;

-- 2. expiresAt NULL erlauben
ALTER TABLE `LocationSharingSession`
  MODIFY COLUMN `expiresAt` datetime DEFAULT NULL;

-- 3. Bestehende leere Token-Spalte korrigieren (falls nicht schon geschehen)
UPDATE `LocationSharingSession` SET `token` = NULL WHERE `token` = '';

-- 4. Tägliches Aufräumen: Sessions löschen wenn seit 7 Tagen keine Locations eingingen
DELIMITER //

CREATE EVENT `clean_old_location_sharing`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
  -- Standorte löschen wo Session > 7 Tage inaktiv ist
  DELETE FROM LocationSharingLocation
    WHERE sessionId IN (
      SELECT id FROM (
        -- Unterquery: Sessions ohne Location seit 7 Tagen
        SELECT s.id FROM LocationSharingSession s
        WHERE (
          SELECT COALESCE(MAX(l.recordedAt), s.createdAt)
          FROM LocationSharingLocation l
          WHERE l.sessionId = s.id
        ) < DATE_SUB(NOW(), INTERVAL 7 DAY)
      ) AS stale
    );

  -- Empfänger löschen für diese Sessions
  DELETE FROM LocationSharingRecipient
    WHERE sessionId IN (
      SELECT id FROM (
        SELECT s.id FROM LocationSharingSession s
        WHERE (
          SELECT COALESCE(MAX(l.recordedAt), s.createdAt)
          FROM LocationSharingLocation l
          WHERE l.sessionId = s.id
        ) < DATE_SUB(NOW(), INTERVAL 7 DAY)
      ) AS stale
    );

  -- Sessions löschen
  DELETE FROM LocationSharingSession
    WHERE (
      SELECT COALESCE(MAX(l.recordedAt), s.createdAt)
      FROM LocationSharingLocation l
      WHERE l.sessionId = id
    ) < DATE_SUB(NOW(), INTERVAL 7 DAY);
END//

DELIMITER ;