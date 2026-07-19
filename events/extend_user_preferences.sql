-- Phase 1: Extend UserPreferences table
-- MySQL-kompatibel: Prüft Spalten einzeln via information_schema

-- 1. Spalten hinzufügen (nur wenn nicht vorhanden)
SET @table = 'UserPreferences';
SET @db = DATABASE();

-- createdAt (neu anlegen, falls nicht vorhanden)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'createdAt');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER timezone'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- emailVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'emailVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN emailVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER createdAt'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- birthdayVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'birthdayVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN birthdayVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER emailVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- syncAvatarFromDiscord
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'syncAvatarFromDiscord');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN syncAvatarFromDiscord TINYINT(1) NOT NULL DEFAULT 1 AFTER birthdayVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- onboardingCompleted
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'onboardingCompleted');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN onboardingCompleted TINYINT(1) NOT NULL DEFAULT 0 AFTER syncAvatarFromDiscord'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- discordVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'discordVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN discordVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER onboardingCompleted'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fluxerVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'fluxerVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN fluxerVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER discordVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- matrixVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'matrixVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN matrixVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER fluxerVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- signalVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'signalVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN signalVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER matrixVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- whatsappVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'whatsappVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN whatsappVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER signalVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- unsplashVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'unsplashVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN unsplashVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsappVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- instagramVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'instagramVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN instagramVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER unsplashVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- mastodonVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'mastodonVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN mastodonVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER instagramVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- pixelfedVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'pixelfedVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN pixelfedVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER mastodonVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- blueskyVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'blueskyVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN blueskyVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER pixelfedVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- youtubeVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'youtubeVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN youtubeVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER blueskyVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- twitchVisibility
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'twitchVisibility');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN twitchVisibility TINYINT(1) NOT NULL DEFAULT 1 AFTER youtubeVisibility'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- updatedAt
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = @table AND COLUMN_NAME = 'updatedAt');
SET @sql = IF(@col = 0, CONCAT('ALTER TABLE ', @table, ' ADD COLUMN updatedAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER createdAt'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Unique Index auf userId (nur wenn nicht vorhanden)
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'UserPreferences'
    AND INDEX_NAME = 'uq_userpreferences_userid'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE UserPreferences ADD UNIQUE INDEX uq_userpreferences_userid (userId)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Foreign Key (nur wenn nicht vorhanden)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'UserPreferences'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_userpreferences_userid'
);
SET @sql2 = IF(@fk_exists = 0,
  'ALTER TABLE UserPreferences ADD CONSTRAINT fk_userpreferences_userid FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 4. Sicherstellen, dass jeder User eine UserPreferences-Row hat
INSERT IGNORE INTO UserPreferences (id, userId, language, theme, primaryColor, timezone)
SELECT
  REPLACE(UUID(), '-', CONCAT('-', UUID(), '-')),
  u.id,
  'de',
  'light',
  '#6366f1',
  'Europe/Berlin'
FROM User u
LEFT JOIN UserPreferences up ON up.userId = u.id
WHERE up.id IS NULL;
