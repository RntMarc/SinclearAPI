-- DAV-Tokens fuer CalDAV-/CardDAV-Zugriff (App-Tokens, Basic Auth)
CREATE TABLE IF NOT EXISTS `DavToken` (
  `id`         varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId`     varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label`      varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyHash`    varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiresAt`  datetime NOT NULL,
  `lastUsedAt` datetime NULL DEFAULT NULL,
  `createdAt`  datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dav_token_user` (`userId`),
  KEY `idx_dav_token_hash` (`keyHash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
