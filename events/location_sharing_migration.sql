-- Location Sharing: Neue Tabellen + Indizes
-- RecipeReview: Unique-Constraint für Duplikatsverhinderung
-- Recipe: Performance-Indizes für Foreign Keys

-- ============================================================
-- 1. Location Sharing Tabellen
-- ============================================================

CREATE TABLE `LocationSharingSession` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ownerId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `durationSeconds` int NOT NULL,
  `frequencySeconds` int NOT NULL DEFAULT 600,
  `isActive` tinyint NOT NULL DEFAULT 1,
  `startedAt` datetime(3) NOT NULL,
  `expiresAt` datetime(3) NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ls_session_owner` (`ownerId`),
  KEY `idx_ls_session_active` (`isActive`, `expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `LocationSharingRecipient` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sessionId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ls_recipient_unique` (`sessionId`, `userId`),
  KEY `idx_ls_recipient_session` (`sessionId`),
  KEY `idx_ls_recipient_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `LocationSharingLocation` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sessionId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `accuracy` double DEFAULT NULL,
  `recordedAt` datetime(3) NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ls_location_session_time` (`sessionId`, `recordedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. RecipeReview: Unique-Constraint (ein Review pro User/Rezept)
-- ============================================================

ALTER TABLE `RecipeReview`
  ADD UNIQUE KEY `idx_review_unique` (`recipeId`, `userId`);

-- ============================================================
-- 3. Recipe: Performance-Indizes für Foreign Keys
-- ============================================================

ALTER TABLE `Recipe`
  ADD KEY `idx_recipe_creator` (`creatorId`);

ALTER TABLE `RecipeBookmark`
  ADD KEY `idx_bookmark_user` (`userId`),
  ADD KEY `idx_bookmark_recipe` (`recipeId`);

ALTER TABLE `RecipeIngredient`
  ADD KEY `idx_ingredient_recipe` (`recipeId`);

ALTER TABLE `RecipeStep`
  ADD KEY `idx_step_recipe` (`recipeId`);

ALTER TABLE `RecipeReview`
  ADD KEY `idx_review_recipe` (`recipeId`),
  ADD KEY `idx_review_user` (`userId`);
