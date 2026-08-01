-- ModerationRequest-Schema: Melde- und Anfragensystem (geplante Funktion A1)
--
-- Nutzer können fremde Inhalte melden (report) oder für eigene Inhalte
-- Aktionen (z.B. Löschung) beim Admin beantragen (deletion, other).
-- Admins bearbeiten die Anfragen und setzen Status + Kommentar.

CREATE TABLE IF NOT EXISTS `ModerationRequest` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requestType` enum('report','deletion','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `objectType` enum('user','forum_post','recipe','explore_place') COLLATE utf8mb4_unicode_ci NOT NULL,
  `objectId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('unread','read','in_work','external_contact','public_decision','accepted','denied','postponed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unread',
  `adminComment` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_moderation_status` (`status`),
  KEY `idx_moderation_user` (`userId`),
  KEY `idx_moderation_object` (`objectType`, `objectId`),
  KEY `idx_moderation_request_type` (`requestType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
