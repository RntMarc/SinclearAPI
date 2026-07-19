-- ============================================================================
-- Phase 3+4: Cleanup
-- Entfernt die alten Spalten aus User, ContactInfo und SocialInfo,
-- nachdem die Daten in UserPreferences migriert wurden.
--
-- ⚠️  ERST AUSFÜHREN NACH migrate_preferences_data.sql
-- ⚠️  ERST AUSFÜHREN NACHDES DIE PHP-CODEÄNDERUNGEN DEPLOYED SIND
-- ============================================================================

-- ──────────────────────────────────────────────────────────────────────────────
-- PHASE 3: Alte Spalten aus User-Tabelle entfernen
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE User
  DROP COLUMN emailVisibility,
  DROP COLUMN birthdayVisibility,
  DROP COLUMN onboardingCompleted;

-- ──────────────────────────────────────────────────────────────────────────────
-- PHASE 4: Visibility-Spalten aus ContactInfo-Tabelle entfernen
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE ContactInfo
  DROP COLUMN discordVisibility,
  DROP COLUMN fluxerVisibility,
  DROP COLUMN matrixVisibility,
  DROP COLUMN signalVisibility,
  DROP COLUMN whatsappVisibility;

-- ──────────────────────────────────────────────────────────────────────────────
-- PHASE 4: Visibility-Spalten aus SocialInfo-Tabelle entfernen
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE SocialInfo
  DROP COLUMN unsplashVisibility,
  DROP COLUMN instagramVisibility,
  DROP COLUMN mastodonVisibility,
  DROP COLUMN pixelfedVisibility,
  DROP COLUMN blueskyVisibility,
  DROP COLUMN youtubeVisibility,
  DROP COLUMN twitchVisibility;
