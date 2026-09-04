-- TravelTrip + TravelEvent: Add citySlug column for external data integration.
-- Stores the InfraNode city slug (e.g. "berlin", "muenchen") so clients
-- know which slug to use for /external-data/ requests.
-- NULL = no supported city (foreign city, no slug available).

ALTER TABLE `TravelTrip`
ADD COLUMN `citySlug` varchar(191) DEFAULT NULL AFTER `forumId`;

ALTER TABLE `TravelEvent`
ADD COLUMN `citySlug` varchar(191) DEFAULT NULL AFTER `OSMID`;
