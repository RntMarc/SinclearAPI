-- TravelEvent: Widen image column from VARCHAR(255) to MEDIUMTEXT.
-- Base64-encoded images (banners) can be up to ~500 KB ≈ 670 000 characters,
-- which far exceeds the original VARCHAR(255).

ALTER TABLE TravelEvent
MODIFY COLUMN image MEDIUMTEXT NULL;
