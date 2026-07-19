-- ============================================================================
-- Phase 3+4: Data-Migration
-- Kopiert alle bestehenden Werte aus User, ContactInfo, SocialInfo
-- in die UserPreferences-Tabelle.
--
-- REIHENFOLGE EINHALTEN:
--   1. UserPreferences-Rows anlegen (falls nicht vorhanden)
--   2. Werte aus User kopieren
--   3. Werte aus ContactInfo kopieren
--   4. Werte aus SocialInfo kopieren
-- ============================================================================

-- ──────────────────────────────────────────────────────────────────────────────
-- SCHRITT 1: Sicherstellen, dass jeder User eine UserPreferences-Row hat
-- ──────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO UserPreferences (id, userId, language, theme, primaryColor, timezone,
    emailVisibility, birthdayVisibility, syncAvatarFromDiscord, onboardingCompleted,
    discordVisibility, fluxerVisibility, matrixVisibility, signalVisibility, whatsappVisibility,
    unsplashVisibility, instagramVisibility, mastodonVisibility, pixelfedVisibility,
    blueskyVisibility, youtubeVisibility, twitchVisibility,
    createdAt, updatedAt)
SELECT
    REPLACE(UUID(), '-', CONCAT('-', UUID(), '-')),
    u.id,
    'de',
    'light',
    '#6366f1',
    'Europe/Berlin',
    1, 1, 1, 0,
    1, 1, 1, 1, 1,
    1, 1, 1, 1,
    1, 1, 1,
    NOW(3), NOW(3)
FROM User u
LEFT JOIN UserPreferences up ON up.userId = u.id
WHERE up.id IS NULL;

-- ──────────────────────────────────────────────────────────────────────────────
-- SCHRITT 2: Werte aus User-Tabelle kopieren
-- emailVisibility, birthdayVisibility, onboardingCompleted
-- ──────────────────────────────────────────────────────────────────────────────

UPDATE UserPreferences up
INNER JOIN User u ON u.id = up.userId
SET
    up.emailVisibility = u.emailVisibility,
    up.birthdayVisibility = u.birthdayVisibility,
    up.onboardingCompleted = u.onboardingCompleted,
    up.updatedAt = NOW(3);

-- ──────────────────────────────────────────────────────────────────────────────
-- SCHRITT 3: Werte aus ContactInfo-Tabelle kopieren
-- discordVisibility, fluxerVisibility, matrixVisibility, signalVisibility,
-- whatsappVisibility
-- ──────────────────────────────────────────────────────────────────────────────

UPDATE UserPreferences up
INNER JOIN ContactInfo ci ON ci.userId = up.userId
SET
    up.discordVisibility = ci.discordVisibility,
    up.fluxerVisibility = ci.fluxerVisibility,
    up.matrixVisibility = ci.matrixVisibility,
    up.signalVisibility = ci.signalVisibility,
    up.whatsappVisibility = ci.whatsappVisibility,
    up.updatedAt = NOW(3);

-- ──────────────────────────────────────────────────────────────────────────────
-- SCHRITT 4: Werte aus SocialInfo-Tabelle kopieren
-- unsplashVisibility, instagramVisibility, mastodonVisibility,
-- pixelfedVisibility, blueskyVisibility, youtubeVisibility, twitchVisibility
-- ──────────────────────────────────────────────────────────────────────────────

UPDATE UserPreferences up
INNER JOIN SocialInfo si ON si.userId = up.userId
SET
    up.unsplashVisibility = si.unsplashVisibility,
    up.instagramVisibility = si.instagramVisibility,
    up.mastodonVisibility = si.mastodonVisibility,
    up.pixelfedVisibility = si.pixelfedVisibility,
    up.blueskyVisibility = si.blueskyVisibility,
    up.youtubeVisibility = si.youtubeVisibility,
    up.twitchVisibility = si.twitchVisibility,
    up.updatedAt = NOW(3);
