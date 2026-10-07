-- Travel/Event cascade delete: referential integrity for the trip and
-- (standalone) event deletion cascade.
--
-- Additive and rerun-safe. Deleting a trip or an event currently leaves
-- several child rows orphaned because they lack foreign keys (TravelEvent.trip,
-- TravelRelation.tripid, EventRelation.eventId, TravelEventTicket.event/trip,
-- TravelAccommodationTrip.tripid). This migration adds ON DELETE CASCADE
-- foreign keys and changes PtJourney.tripId from SET NULL to CASCADE so that
-- linked public-transport journeys are removed together with their trip.
--
-- The forum (and its posts/votes/comments/members) and the underlying chat
-- conversation are cleaned up at application level (they have no direct
-- foreign key to TravelTrip), not here.
--
-- Note: there is no local migration runner; the file is applied by the
-- operator on the server.

-- 1) Remove existing orphans before adding the foreign keys (defensive, so
--    the ALTER statements cannot fail on legacy data).

DELETE e FROM `TravelEvent` e
LEFT JOIN `TravelTrip` t ON t.`id` = e.`trip`
WHERE e.`trip` IS NOT NULL AND t.`id` IS NULL;

DELETE r FROM `TravelRelation` r
LEFT JOIN `TravelTrip` t ON t.`id` = r.`tripid`
WHERE t.`id` IS NULL;

DELETE er FROM `EventRelation` er
LEFT JOIN `TravelEvent` e ON e.`ID` = er.`eventId`
WHERE e.`ID` IS NULL;

DELETE tkt FROM `TravelEventTicket` tkt
LEFT JOIN `TravelEvent` e ON e.`ID` = tkt.`event`
LEFT JOIN `TravelTrip` t ON t.`id` = tkt.`trip`
WHERE (tkt.`event` IS NOT NULL AND e.`ID` IS NULL)
   OR (tkt.`trip` IS NOT NULL AND t.`id` IS NULL);

DELETE lat FROM `TravelAccommodationTrip` lat
LEFT JOIN `TravelTrip` t ON t.`id` = lat.`tripid`
WHERE t.`id` IS NULL;

-- 2) Add missing indexes required as a prefix for the foreign keys
--    (idempotent via information_schema).

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEvent'
      AND INDEX_NAME = 'idx_travel_event_trip'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `TravelEvent` ADD KEY `idx_travel_event_trip` (`trip`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEventTicket'
      AND INDEX_NAME = 'idx_travel_event_ticket_event'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `TravelEventTicket` ADD KEY `idx_travel_event_ticket_event` (`event`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEventTicket'
      AND INDEX_NAME = 'idx_travel_event_ticket_trip'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `TravelEventTicket` ADD KEY `idx_travel_event_ticket_trip` (`trip`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Add the ON DELETE CASCADE foreign keys (idempotent via
--    information_schema.REFERENTIAL_CONSTRAINTS).

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEvent'
      AND CONSTRAINT_NAME = 'fk_travel_event_trip'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `TravelEvent` ADD CONSTRAINT `fk_travel_event_trip` FOREIGN KEY (`trip`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelRelation'
      AND CONSTRAINT_NAME = 'fk_travel_relation_trip'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `TravelRelation` ADD CONSTRAINT `fk_travel_relation_trip` FOREIGN KEY (`tripid`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'EventRelation'
      AND CONSTRAINT_NAME = 'fk_event_relation_event'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `EventRelation` ADD CONSTRAINT `fk_event_relation_event` FOREIGN KEY (`eventId`) REFERENCES `TravelEvent` (`ID`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEventTicket'
      AND CONSTRAINT_NAME = 'fk_travel_event_ticket_event'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `TravelEventTicket` ADD CONSTRAINT `fk_travel_event_ticket_event` FOREIGN KEY (`event`) REFERENCES `TravelEvent` (`ID`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelEventTicket'
      AND CONSTRAINT_NAME = 'fk_travel_event_ticket_trip'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `TravelEventTicket` ADD CONSTRAINT `fk_travel_event_ticket_trip` FOREIGN KEY (`trip`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelAccommodationTrip'
      AND CONSTRAINT_NAME = 'fk_travel_accommodation_trip_trip'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `TravelAccommodationTrip` ADD CONSTRAINT `fk_travel_accommodation_trip_trip` FOREIGN KEY (`tripid`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) PtJourney.tripId: replace the existing ON DELETE SET NULL foreign key
--    with an ON DELETE CASCADE one, so linked journeys are deleted together
--    with the trip.

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'PtJourney'
      AND CONSTRAINT_NAME = 'fk_pt_journey_trip'
);
SET @sql := IF(
    @fk_exists = 1,
    'ALTER TABLE `PtJourney` DROP FOREIGN KEY `fk_pt_journey_trip`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'PtJourney'
      AND CONSTRAINT_NAME = 'fk_pt_journey_trip_cascade'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `PtJourney` ADD CONSTRAINT `fk_pt_journey_trip_cascade` FOREIGN KEY (`tripId`) REFERENCES `TravelTrip` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
