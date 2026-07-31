-- Fügt eine Photo-Spalte zur DiscoverReview-Tabelle hinzu.
-- Speichert Base64-kodierte Bilddaten (JPEG/PNG/WebP, max 200 KB).
ALTER TABLE DiscoverReview
    ADD COLUMN photo TEXT NULL AFTER comment;
