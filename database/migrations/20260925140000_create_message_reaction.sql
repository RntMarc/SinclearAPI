-- Chat-Reaktionen: ein Emoji pro Nutzer und Nachricht (Toggle ueber add/remove).
--
-- Hinweis: Diese Datei ist NICHT blind rerun-faehig. Vor der Ausfuehrung pruefen,
-- ob die Tabelle bereits existiert.
--
-- Referenz: MessageReaction referenziert DirectMessage (nicht eine generische
-- Message-Tabelle) und User. Beim Hard-Delete alter Nachrichten (Cron) werden
-- die Reaktionen per FK-Cascade mit entfernt.

CREATE TABLE IF NOT EXISTS `MessageReaction` (
  `id` varchar(191) NOT NULL,
  `messageId` varchar(191) NOT NULL,
  `userId` varchar(191) NOT NULL,
  `emoji` varchar(32) NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reaction_msg_user_emoji` (`messageId`,`userId`,`emoji`),
  KEY `idx_reaction_message` (`messageId`),
  CONSTRAINT `fk_reaction_message` FOREIGN KEY (`messageId`) REFERENCES `DirectMessage` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reaction_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
