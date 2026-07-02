-- Recipe-Performance-Indizes: Foreign-Key-Spalten für WHERE/JOIN-Abfragen

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
