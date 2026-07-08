-- Location Sharing: Sharing-Mode + automatisches Aufräumen
-- sharingMode: 'location' (nur aktueller Standort) oder 'route' (Strecke mit allen Punkten)
-- MySQL Event: Löscht Sessions älter als 7 Tage mit zugehörigen Daten

-- 1. Neue Spalte sharingMode hinzufügen
ALTER TABLE `LocationSharingSession`
  ADD COLUMN `sharingMode` enum('location','route') NOT NULL DEFAULT 'location' AFTER `frequencySeconds`;

-- 2. MySQL Event für automatisches Aufräumen (täglich)
DELIMITER //

CREATE EVENT `clean_old_location_sharing`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
  -- Zuerst Standorte löschen
  DELETE FROM LocationSharingLocation
    WHERE sessionId IN (
      SELECT id FROM LocationSharingSession
      WHERE createdAt < DATE_SUB(NOW(), INTERVAL 7 DAY)
    );
  -- Dann Empfänger löschen
  DELETE FROM LocationSharingRecipient
    WHERE sessionId IN (
      SELECT id FROM LocationSharingSession
      WHERE createdAt < DATE_SUB(NOW(), INTERVAL 7 DAY)
    );
  -- Zum Schluss Sessions löschen
  DELETE FROM LocationSharingSession
    WHERE createdAt < DATE_SUB(NOW(), INTERVAL 7 DAY);
END//

DELIMITER ;
