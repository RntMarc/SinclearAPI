-- Migration: Create chat / direct message tables
-- ChatConversation: 1:1 (later group) conversations
-- ChatParticipant: membership + read cursor per user
-- DirectMessage: actual messages with seq-based sync cursor
-- ChatPresence: push suppression for active pollers
-- ChatTyping: ephemeral typing indicator

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `ChatConversation` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('direct','group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ChatConversation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conversation_updated` (`updatedAt`);

CREATE TABLE `ChatParticipant` (
  `conversationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `joinedAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `lastReadSeq` bigint unsigned NOT NULL DEFAULT '0',
  `lastSeenAt` datetime(3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ChatParticipant`
  ADD PRIMARY KEY (`conversationId`,`userId`),
  ADD KEY `idx_participant_user` (`userId`);

ALTER TABLE `ChatParticipant`
  ADD CONSTRAINT `fk_participant_conversation` FOREIGN KEY (`conversationId`) REFERENCES `ChatConversation` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_participant_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;

CREATE TABLE `DirectMessage` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seq` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senderId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('text','image','location') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `clientId` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `editedAt` datetime(3) DEFAULT NULL,
  `deletedAt` datetime(3) DEFAULT NULL,
  `deletedBy` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `DirectMessage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_dm_seq` (`seq`),
  ADD KEY `idx_dm_conversation_seq` (`conversationId`,`seq`),
  ADD KEY `idx_dm_sender` (`senderId`),
  ADD UNIQUE KEY `uk_dm_sender_client` (`senderId`,`clientId`);

ALTER TABLE `DirectMessage`
  ADD CONSTRAINT `fk_dm_conversation` FOREIGN KEY (`conversationId`) REFERENCES `ChatConversation` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dm_sender` FOREIGN KEY (`senderId`) REFERENCES `User` (`id`) ON DELETE CASCADE;

CREATE TABLE `ChatPresence` (
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activeUntil` datetime(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ChatPresence`
  ADD PRIMARY KEY (`userId`);

ALTER TABLE `ChatPresence`
  ADD CONSTRAINT `fk_presence_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;

CREATE TABLE `ChatTyping` (
  `conversationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiresAt` datetime(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ChatTyping`
  ADD PRIMARY KEY (`conversationId`,`userId`);

ALTER TABLE `ChatTyping`
  ADD CONSTRAINT `fk_typing_conversation` FOREIGN KEY (`conversationId`) REFERENCES `ChatConversation` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_typing_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE;

CREATE TABLE `ChatEvent` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seq` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actorId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('message_created','message_edited','message_deleted') COLLATE utf8mb4_unicode_ci NOT NULL,
  `messageId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ChatEvent`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_ce_seq` (`seq`),
  ADD KEY `idx_ce_conv_seq` (`conversationId`,`seq`),
  ADD KEY `idx_ce_message` (`messageId`);

ALTER TABLE `ChatEvent`
  ADD CONSTRAINT `fk_ce_conversation` FOREIGN KEY (`conversationId`) REFERENCES `ChatConversation` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ce_message` FOREIGN KEY (`messageId`) REFERENCES `DirectMessage` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ce_actor` FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE CASCADE;
