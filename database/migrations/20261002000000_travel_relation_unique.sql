-- TravelRelation: (userid, tripid) eindeutig.
--
-- Additiv und rerun-sicher. Die Aktivierung einer Planungsreise uebernimmt
-- Planungsmitglieder in TravelRelation. Ohne Unique-Key konnte ein
-- gleichzeitiger operativer Schreibpfad denselben Teilnehmer doppelt anlegen
-- (und dadurch z. B. Rollen/Unterkuenfte verlieren). Der Unique-Key macht die
-- Uebernahme zusaetzlich DB-seitig idempotent.
--
-- Vor dem Setzen des Keys werden etwaige Bestandsduplikate zusammengefuehrt,
-- ohne Information zu verlieren: eine 'leader'-Rolle und eine gesetzte
-- Unterkunft bleiben erhalten. Es wird KEINE Zeile ausser halben Duplikaten
-- geloescht.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet.

-- 1) Duplikatgruppen in eine Hilfstabelle materialisieren (minimale ID bleibt
--    bestehen). Die Anreicherung erfolgt hier per INSERT/SELECT, sodass die
--    spaeteren UPDATEs nicht auf die eigene Zieltabelle zugreifen muessen
--    (vermeidet MySQL-Fehler 1093).
DROP TABLE IF EXISTS `_tmp_travelrelation_dupes`;

CREATE TABLE `_tmp_travelrelation_dupes` (
  `userid` varchar(191) NOT NULL,
  `tripid` varchar(191) NOT NULL,
  `keepId` varchar(191) NOT NULL,
  `hasLeader` tinyint NOT NULL DEFAULT 0,
  `accId` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`userid`, `tripid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_tmp_travelrelation_dupes` (`userid`, `tripid`, `keepId`, `hasLeader`, `accId`)
SELECT
    t.`userid`,
    t.`tripid`,
    MIN(t.`ID`),
    MAX(t.`role` = 'leader'),
    (
        SELECT x.`ID`
        FROM `TravelRelation` x
        WHERE x.`userid` = t.`userid`
          AND x.`tripid` = t.`tripid`
          AND x.`accommodation` IS NOT NULL
        ORDER BY x.`ID`
        LIMIT 1
    )
FROM `TravelRelation` t
GROUP BY t.`userid`, t.`tripid`
HAVING COUNT(*) > 1;

-- 2) 'leader' erhalten: die bleibende Zeile wird zur Leitung, wenn irgendein
--    Duplikat Leitung war.
UPDATE `TravelRelation` r
JOIN `_tmp_travelrelation_dupes` d ON r.`ID` = d.`keepId`
SET r.`role` = 'leader'
WHERE d.`hasLeader` = 1;

-- 3) Unterkunft erhalten: eine gesetzte Unterkunft eines Duplikats wird auf die
--    bleibende Zeile uebertragen, wenn diese noch keine hat.
UPDATE `TravelRelation` r
JOIN `_tmp_travelrelation_dupes` d ON r.`ID` = d.`keepId`
JOIN `TravelRelation` src ON src.`ID` = d.`accId`
SET r.`accommodation` = COALESCE(r.`accommodation`, src.`accommodation`);

-- 4) Ueberzaehlige Duplikate entfernen.
DELETE r FROM `TravelRelation` r
JOIN `_tmp_travelrelation_dupes` d
    ON d.`userid` = r.`userid` AND d.`tripid` = r.`tripid`
WHERE r.`ID` <> d.`keepId`;

DROP TABLE IF EXISTS `_tmp_travelrelation_dupes`;

-- 5) Unique-Key setzen (nur falls noch nicht vorhanden).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TravelRelation'
      AND INDEX_NAME = 'uniq_travelrelation_user_trip'
);

SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `TravelRelation`
       ADD UNIQUE KEY `uniq_travelrelation_user_trip` (`userid`, `tripid`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
