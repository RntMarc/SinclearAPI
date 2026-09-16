-- Migration: Drop chat realtime tables (Centrifugo v6 ersetzt DB-Realtime)
-- Entfernt die alten Event/Presence/Typing-Tabellen sowie Legacy-Tabellen
-- aus der Live-DB (sibed-nodata.sql, nicht mehr im Code genutzt).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Realtime-Tabellen (durch Centrifugo ersetzt)
DROP TABLE IF EXISTS `ChatEvent`;
DROP TABLE IF EXISTS `ChatTyping`;
DROP TABLE IF EXISTS `ChatPresence`;

-- Legacy-Tabellen (nicht mehr im Code referenziert)
DROP TABLE IF EXISTS `ChatRoomMembers`;   -- vor ChatRooms (FK)
DROP TABLE IF EXISTS `ChatRooms`;
DROP TABLE IF EXISTS `ChatMessages`;
DROP TABLE IF EXISTS `ChatReadReceipt`;
DROP TABLE IF EXISTS `DirectChat`;
DROP TABLE IF EXISTS `UserPresence`;
DROP TABLE IF EXISTS `SseEvent`;

-- Legacy DB-Event (bezog sich auf ChatMessages/ChatRooms)
DROP EVENT IF EXISTS `cleanup_messages`;
