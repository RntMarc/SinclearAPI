-- Cron-Scheduler: Tracking-Tabelle für geplante Aufgaben

CREATE TABLE IF NOT EXISTS `CronSchedule` (
  `taskName` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastRunAt` datetime(3) DEFAULT NULL,
  `lastDurationMs` int DEFAULT NULL,
  `lastStatus` enum('success','failed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastError` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`taskName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
