-- OtpToken: Add a type discriminator column to separate the authentication flows
-- that share the table. This removes the fragile TTL-based disambiguation in
-- AuthController and makes lookups flow-specific at the repository level.
--
--   email_otp        (600s)  E-Mail-OTP for login, email change and admin login.
--                            `email` contains the target email address.
--   discord_pairing  (120s)  Discord pairing code for login / registration.
--                            `email` contains the linked user's email address.
--   discord_relink   (120s)  Discord relink pairing code.
--                            `email` contains JSON metadata (see
--                            DiscordOAuthService::processRelinkCallback).
--
-- Existing rows are backfilled: relink tokens carry JSON metadata in `email`,
-- pairing codes have a short TTL (<= 150s buffer).

ALTER TABLE OtpToken
  ADD COLUMN `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email_otp' AFTER code;

UPDATE OtpToken SET type = 'discord_relink' WHERE JSON_VALID(email) = 1;

UPDATE OtpToken
SET type = 'discord_pairing'
WHERE type = 'email_otp'
  AND TIMESTAMPDIFF(SECOND, createdAt, expiresAt) <= 150;