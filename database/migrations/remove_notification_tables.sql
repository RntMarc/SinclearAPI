-- Migration: Remove notification system tables and events
-- Reverts the notification system (in-app + FCM push) completely.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- 1. Scheduled Event entfernen (Cron-Job für Notification-Bereinigung)
DROP EVENT IF EXISTS `clean_old_notifications`;

-- 2. Tabellen in correcter Reihenfolge (keine FK-Abhängigkeiten hier)
DROP TABLE IF EXISTS `UserDevice`;
DROP TABLE IF EXISTS `PushSubscription`;
DROP TABLE IF EXISTS `Notification`;
