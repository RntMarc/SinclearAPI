/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

DELIMITER //
CREATE DEFINER=`USER320383_admin`@`%` EVENT `clean_old_location_sharing` ON SCHEDULE EVERY 1 DAY STARTS '2026-07-09 01:48:50' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
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

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
