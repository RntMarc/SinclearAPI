-- Migration: Add 'unsplash' to ExternalDataCache.source enum
-- Applied: 2026-09-14
-- Author: SinclearAPI Agent
--
-- The external data cache is reused for cached Unsplash API responses
-- (data_type = 'unsplash_user_photos', location_key = unsplash username).
-- This only widens the source enum; existing rows are unaffected.

ALTER TABLE `ExternalDataCache`
  MODIFY COLUMN `source`
    enum('infranode','open-meteo','mixed','unsplash')
    COLLATE utf8mb4_unicode_ci NOT NULL;
