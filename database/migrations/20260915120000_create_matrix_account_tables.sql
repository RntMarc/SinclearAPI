-- Migration: Matrix-Auslagerung (Application Service)
-- MatrixAccount: dauerhafte Zuordnung Sinclear-User -> Matrix-Account (Ist-/Soll-Zustand)
-- MatrixSyncOperation: Outbox fuer ausstehende/externe Operationen (Retry/Backoff)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE IF NOT EXISTS `MatrixAccount` (
  `userId`            varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `localpart`         varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `matrixUserId`      varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passwordEncrypted` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `displayNameSynced` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt`         datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt`         datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`userId`),
  UNIQUE KEY `uk_matrix_localpart` (`localpart`),
  CONSTRAINT `fk_matrix_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `MatrixSyncOperation` (
  `id`            varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId`        varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type`          enum('create','displayname') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload`       json DEFAULT NULL,
  `status`        enum('pending','done','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts`      int unsigned NOT NULL DEFAULT '0',
  `nextAttemptAt` datetime(3) DEFAULT NULL,
  `lastError`     text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt`     datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completedAt`   datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_matrix_op_due` (`status`,`nextAttemptAt`),
  KEY `idx_matrix_op_user` (`userId`),
  CONSTRAINT `fk_matrix_op_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
