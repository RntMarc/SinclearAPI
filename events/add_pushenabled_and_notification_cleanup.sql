-- Migration: Add pushEnabled column to UserDevice table
-- and create notification cleanup event

-- 1. Add pushEnabled column to UserDevice
ALTER TABLE `UserDevice`
  ADD COLUMN `pushEnabled` tinyint(1) NOT NULL DEFAULT '0' AFTER `pushToken`;

-- 2. MySQL Event: Clean up old notifications (older than 30 days)
DELIMITER //

CREATE EVENT IF NOT EXISTS clean_old_notifications
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
COMMENT 'Removes notifications older than 30 days'
DO
BEGIN
    DELETE FROM Notification
    WHERE createdAt < DATE_SUB(NOW(), INTERVAL 30 DAY);
END //

DELIMITER ;
