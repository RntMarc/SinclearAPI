-- Exklusive Verknuepfung einer Account-ID mit genau einem Beyond-Nutzer.
--
-- Fuer Minecraft (game = 'minecraft') soll eine UUID nur von einem Nutzer
-- verknuepft werden koennen. Der bisherige nicht-eindeutige Index wird durch
-- einen UNIQUE-Index auf (game, accountId) ersetzt; das gilt damit auch fuer
-- Deadlock (ein Steam-Account = ein Beyond-Nutzer).
--
-- Vorab pruefen (muss leer sein, sonst erst die Duplikate bereinigen):
--   SELECT game, accountId, COUNT(*) AS c
--   FROM GameAccount
--   GROUP BY game, accountId
--   HAVING c > 1;

ALTER TABLE `GameAccount`
  DROP INDEX `GameAccount_game_accountId_idx`,
  ADD UNIQUE KEY `GameAccount_game_accountId_unique` (`game`, `accountId`);
