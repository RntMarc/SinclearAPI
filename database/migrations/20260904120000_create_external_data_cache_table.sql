-- Migration: Create external data cache table
-- Stores cached responses from external APIs (InfraNode, Open-Meteo, etc.)
-- Extensible for any data type: weather, traffic, holidays, demographics, events, etc.
-- TTL is per-entry (expires_at), set based on data_type in external_data.cache_ttl config.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `ExternalDataCache` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'weather, weather_warnings, pollen_uv, air_quality, traffic, holidays, demographics, events, etc.',
  `location_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'City slug (e.g. berlin) or "lat,lon" for coordinates. No validation — caller must provide correct slug.',
  `source` enum('infranode','open-meteo','mixed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL COMMENT 'Cached response data from external API',
  `expires_at` datetime(3) NOT NULL COMMENT 'When this cache entry becomes stale',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cache_type_location` (`data_type`, `location_key`),
  KEY `idx_cache_expires` (`expires_at`),
  KEY `idx_cache_type` (`data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
