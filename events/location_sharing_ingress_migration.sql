-- Location Sharing: Third-Party Integration Tokens
-- Fügt einen einzigartigen Token pro Session für unauthentifizierte Standortübermittlung hinzu

-- 1. Spalte hinzufügen (erlaubt暂时 leere Werte)
ALTER TABLE `LocationSharingSession`
  ADD COLUMN `token` varchar(64) NOT NULL DEFAULT '' AFTER `id`;

-- 2. Existierende Sessions mit einzigartigen Tokens befüllen
UPDATE `LocationSharingSession` SET `token` = LOWER(HEX(RANDOM_BYTES(32))) WHERE `token` = '';

-- 3. Unique Key hinzufügen (nachdem alle Tokens generiert wurden)
ALTER TABLE `LocationSharingSession`
  ADD UNIQUE KEY `idx_ls_session_token` (`token`);
