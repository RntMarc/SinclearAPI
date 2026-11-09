-- Seed: Rocket Boost als erstes Arcade-Spiel
--
-- Rerun-sicher: Existiert bereits ein Spiel mit dem Slug "rocket-boost"
-- (z. B. im Admin-Dashboard angelegt), bleibt der Datensatz unverändert.
-- So überschreibt ein erneutes Anwenden keine manuellen Anpassungen.
--
-- Die Spiel-Dateien liegen unter public/arcade/rocket-boost/; die WASM-
-- Artefakte werden beim Deploy hochgeladen und sind nicht im Repository.

INSERT INTO `ArcadeGame`
  (`id`, `slug`, `title`, `description`, `thumbnailUrl`, `scoreDirection`, `isActive`, `sortOrder`, `createdAt`, `updatedAt`)
VALUES
  (
    '019a4b1c-7f3a-7c2e-9d61-2b8f0a5c4e10',
    'rocket-boost',
    'Rocket Boost',
    'Flieg die Rakete durch die Korridore der Raumstation.',
    '/api/arcade/rocket-boost/thumbnail.png',
    'desc',
    1,
    0,
    UTC_TIMESTAMP(3),
    UTC_TIMESTAMP(3)
  )
ON DUPLICATE KEY UPDATE `slug` = `slug`;
