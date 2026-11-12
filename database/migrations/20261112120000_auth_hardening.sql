-- Migration: Auth-Härtung (Refresh-Token-Rotation + OTP-Sicherheit)
-- Autor: SinclearAPI Agent
--
-- Teil 1: Refresh-Token-Rotation mit Grace-Period
--   previousTokenHash: verweist vom Nachfolger auf den ersetzten Token, damit
--     ein Replay innerhalb der Grace-Periode erkannt und toleriert werden kann.
--   graceUntil: Zeitpunkt, bis zu dem ein Replay des Vorgängers toleriert wird.
--
-- Teil 2: OTP-Sicherheit
--   attempts: Fehlversuchszähler pro Code (Brute-Force-Schutz).
--   sessionId: bindet Discord-Pairing-Codes an die auslösende Session
--     (verhindert, dass ein geratener Code eine fremde Session einlöst).
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. ALTER TABLE ist nicht rerun-sicher,
-- daher genau einmal ausführen.

ALTER TABLE `RefreshToken`
  ADD COLUMN `previousTokenHash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tokenHash`,
  ADD COLUMN `graceUntil` datetime DEFAULT NULL AFTER `expiresAt`,
  ADD KEY `idx_rt_previous_hash` (`previousTokenHash`);

ALTER TABLE `OtpToken`
  ADD COLUMN `attempts` int NOT NULL DEFAULT 0 AFTER `code`,
  ADD COLUMN `sessionId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `type`;
