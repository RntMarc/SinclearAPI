-- Migration: Push-Subscriptions pro Endpoint eindeutig, ohne 255-Zeichen-Prefix
-- Autor: SinclearAPI Agent
--
-- Problem: `idx_pushsub_endpoint` war ein UNIQUE-Index auf `endpoint(255)`.
-- Zwei Endpoints, die sich erst nach Zeichen 255 unterscheiden (lange
-- WebPush-/FCM-/UnifiedPush-Tokens), kollidierten deshalb beim INSERT, obwohl
-- `findByEndpoint()` sie korrekt als verschieden erkennt -> HTTP 500.
--
-- Lösung: eine deterministische SHA-256-Spalte über den vollständigen Endpoint
-- mit UNIQUE-Index. `endpoint` bleibt TEXT, damit auch sehr lange Tokens
-- vollständig gespeichert werden.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. ALTER TABLE ist nicht rerun-sicher,
-- daher genau einmal ausführen.

ALTER TABLE `PushSubscription`
  DROP INDEX `idx_pushsub_endpoint`,
  ADD COLUMN `endpointHash` char(64) CHARACTER SET ascii COLLATE ascii_bin
    GENERATED ALWAYS AS (SHA2(`endpoint`, 256)) STORED AFTER `endpoint`,
  ADD UNIQUE KEY `idx_pushsub_endpoint_hash` (`endpointHash`);
