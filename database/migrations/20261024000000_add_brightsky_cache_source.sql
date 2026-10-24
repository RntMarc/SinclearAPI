-- Migration: 'brightsky' zum ExternalDataCache.source Enum hinzufuegen
-- Autor: SinclearAPI Agent
--
-- Seit d83f50b (2026-09-14) werden Wetterwarnungen fuer Standorte ohne
-- InfraNode-Slug ueber BrightSky geladen und mit source = 'brightsky' im
-- ExternalDataCache abgelegt. Das dazugehoerige ENUM wurde damals nicht
-- erweitert, wodurch der Cache-Insert im STRICT_TRANS_TABLES-Modus mit
-- "Data truncated for column 'source'" (1265) abbrach und
-- GET /external-data/weather/warnings?lat=..&lon=.. mit HTTP 500 antwortete.
--
-- 'brightsky' wird bewusst ANS ENDE gehaengt: bestehende Zeilen speichern den
-- Enum-Index, eine Umsortierung wuerde die bereits gecachten Quellen
-- (infranode, open-meteo, mixed, unsplash) verfaelschen.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet. MODIFY COLUMN ist rerun-sicher und
-- korrigiert zugleich eine moegliche Enum-Drift.

ALTER TABLE `ExternalDataCache`
  MODIFY COLUMN `source`
    enum('infranode','open-meteo','mixed','unsplash','brightsky')
    COLLATE utf8mb4_unicode_ci NOT NULL;
