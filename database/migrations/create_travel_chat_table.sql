-- Migration: Create TravelChat table
-- Links a group ChatConversation to a TravelTrip or TravelEvent.
-- Members are derived from the linked object's participants (TravelRelation / EventRelation)
-- and mirrored into the existing ChatParticipant table.

CREATE TABLE `TravelChat` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversationId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tripId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eventId` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `TravelChat`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_travelchat_conversation` (`conversationId`),
  ADD KEY `idx_travelchat_trip` (`tripId`),
  ADD KEY `idx_travelchat_event` (`eventId`);

ALTER TABLE `TravelChat`
  ADD CONSTRAINT `fk_travelchat_conversation` FOREIGN KEY (`conversationId`) REFERENCES `ChatConversation` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_travelchat_trip` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_travelchat_event` FOREIGN KEY (`eventId`) REFERENCES `TravelEvent` (`ID`) ON DELETE CASCADE;

-- Ensure exactly one of tripId or eventId is set (enforced at app level too)
ALTER TABLE `TravelChat`
  ADD CONSTRAINT `chk_travelchat_one_target` CHECK (
    (`tripId` IS NOT NULL AND `eventId` IS NULL) OR
    (`tripId` IS NULL AND `eventId` IS NOT NULL)
  );
