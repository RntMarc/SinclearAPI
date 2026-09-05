-- TravelEvent + TravelAccommodation: Add citySlug column for external data integration.
-- Stores the InfraNode city slug (e.g. "berlin", "muenchen") so clients
-- know which slug to use for /external-data/ requests.
-- NULL = no supported city (foreign city, no slug available).

ALTER TABLE `TravelEvent`
ADD COLUMN `citySlug` varchar(191) DEFAULT NULL AFTER `OSMID`;

ALTER TABLE `TravelAccommodation`
ADD COLUMN `citySlug` varchar(191) DEFAULT NULL AFTER `ishotel`;
