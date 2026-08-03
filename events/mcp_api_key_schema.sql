CREATE TABLE IF NOT EXISTS `McpApiKey` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyHash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiresAt` datetime NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_mcp_key_hash` (`keyHash`),
  KEY `idx_mcp_key_user` (`userId`),
  KEY `idx_mcp_key_expires` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE EVENT `clean_expired_mcp_keys`
ON SCHEDULE EVERY 1 WEEK
STARTS CURRENT_TIMESTAMP + INTERVAL (7 - WEEKDAY(CURRENT_TIMESTAMP)) DAY + INTERVAL 3 HOUR
DO
BEGIN
    DELETE FROM McpApiKey WHERE expiresAt < NOW();
END//

DELIMITER ;
