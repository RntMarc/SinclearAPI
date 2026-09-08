# Externe Daten

Aggregierte externe Daten (Wetter, Luftqualität, Pollenbelastung) für beliebige Standorte.

## Übersicht

| Endpoint | Beschreibung | InfraNode | Open-Meteo |
|----------|-------------|-----------|------------|
| `GET /external-data/weather` | Aktuelles Wetter | ✅ (DE) | ✅ (global) |
| `GET /external-data/weather/warnings` | Wetterwarnungen | ✅ (DE) | ❌ |
| `GET /external-data/pollen-uv` | Pollenbelastung + UV-Index | ✅ (DE) | ✅ (nur UV) |
| `GET /external-data/air-quality` | Luftqualität (PM10, NO2, O3…) | ✅ (DE) | ✅ (global) |
| `GET /external-data/types` | Verfügbare Datentypen | — | — |
| `GET /external-data/locations/search` | Orte für Wetterdaten suchen | ✅ | ✅ (Nominatim) |
| `GET /external-data/locations` | Alle InfraNode-Orte auflisten | ✅ | — |

## Authentifizierung

Alle Endpoints erfordern ein gültiges JWT im `Authorization: Bearer <token>` Header.

## Standortangabe

Für Wetter-, Warn- und Luftqualitäts-Endpoints muss ein Standort angegeben werden:

- **`city_slug`** — Deutscher City-Slug (z.B. `berlin`, `muenchen`). Muss korrekt sein; keine Validierung.
- **`lat` + `lon`** — Koordinaten (Dezimalgrad). Für Open-Meteo Fallback.

Mindestens eines von beiden muss angegeben werden.

## Orts-Suche

### `GET /external-data/locations/search`

Sucht nach Orten für Wetterdaten. Kombiniert InfraNode-gestützte deutsche Städte mit weltweiten Orten über Nominatim-Geocoding.

**Parameter:**
- `q` (required) — Suchbigriff, mindestens 2 Zeichen

**Antwort:**
```json
{
  "data": [
    {
      "name": "Berlin",
      "slug": "berlin",
      "lat": 52.52,
      "lon": 13.405,
      "recommended": true,
      "source": "infranode",
      "state": "BE",
      "population": 3782202
    },
    {
      "name": "Paris, Île-de-France, Frankreich",
      "slug": null,
      "lat": 48.8566,
      "lon": 2.3522,
      "recommended": false,
      "source": "nominatim",
      "state": "Île-de-France",
      "population": null
    }
  ]
}
```

**Felder:**
- `slug` — InfraNode-Slug für unterstützte deutsche Städte, `null` für weltweite Orte
- `recommended` — `true` wenn InfraNode-gestützt (empfohlen für /external-data Endpunkte)
- `source` — `infranode` oder `nominatim`
- `state` — Bundesland (bei InfraNode) bzw. Staat/Region (bei Nominatim)
- `population` — Einwohnerzahl (nur bei InfraNode)

**Slug-Zuordnung:** Die Suche ordnet Nominatim-Treffer dynamisch über die
InfraNode-Städte-Liste zu — primär über die Wikidata-QID aus `extratags`,
zurückfallend auf die OSM-Relation-ID (`osm_relation`). Dadurch bleibt das
Mapping automatisch im Sync mit InfraNode (keine hardcoded OSM-ID-Liste).
Der Client hebt InfraNode-Treffer in der Ergebnisseite hervor (Stern).

### `GET /external-data/locations`

Gibt alle von InfraNode unterstützten deutschen Städte zurück.

**Antwort:**
```json
{
  "data": [
    {
      "name": "Berlin",
      "slug": "berlin",
      "lat": 52.52,
      "lon": 13.405,
      "state": "BE",
      "population": 3782202,
      "coverage": "full"
    }
  ]
}
```

## Datenfluss

```
Client → API → Cache (MySQL ExternalDataCache)?
                    ↓ (Treffert)
                Cache-Antwort
                    ↓ (kein Treffer)
                InfraNode (DE-Städte)?
                    ↓ (404 oder kein DE)
                Open-Meteo (global)
                    ↓
                Cache speichern → Antwort
```

## Caching

Antworten werden in der MySQL-Tabelle `ExternalDataCache` gecacht:

| Datentyp | Standard-TTL |
|----------|-------------|
| `weather` | 600 Sek. (10 Min.) |
| `weather_warnings` | 300 Sek. (5 Min.) |
| `pollen_uv` | 1800 Sek. (30 Min.) |
| `air_quality` | 600 Sek. (10 Min.) |
| `default` | 1800 Sek. (30 Min.) |

TTLs können über Umgebungsvariablen `EXTERNAL_DATA_CACHE_<TYP>` konfiguriert werden.

Cache-Einträge laufen nach Ablauf automatisch ab und werden durch den Cron-Job `cleanup_external_data_cache` (alle 24h) bereinigt.

## Quellen

| Quelle | Nutzung | Lizenz |
|--------|---------|--------|
| InfraNode.dev | Deutsche Städte (Wetter, Warnungen, Pollen, UV, Luftqualität) | Open Data |
| Open-Meteo.com | Globaler Wetter- und Luftqualitäts-Fallback | Non-commercial |

## Lückenhafte Daten

Die API liefert immer die bestmögliche Antwort. Fehlende Abschnitte werden in `meta.missing_sections` aufgelistet, aber **nie** als `null` im `data`-Objekt zurückgegeben. Fehlende Felder werden einfach weggelassen.

## Datenquelle (source)

Jede Antwort enthält in `meta.source` die Information, welche Quelle die Daten liefert:

| `meta.source` | Beschreibung |
|---------------|--------------|
| `infranode` | Daten ausschließlich von InfraNode (deutsche Städte) |
| `open-meteo` | Daten ausschließlich von Open-Meteo (global) |
| `mixed` | InfraNode + Open-Meteo kombiniert |
| `cache` | Gecachte Antwort aus der Datenbank |

Zusätzlich enthält `data._attribution` (sofern vorhanden) die Quellen-Angabe des Providers:

```json
{
  "_attribution": {
    "text": "Open-Meteo (open-meteo.com) — Non-commercial use",
    "url": "https://open-meteo.com/en/docs"
  }
}
```

Beispiel bei fehlenden Pollen-Daten:
```json
{
  "data": {
    "current": { "temperature_c": 18.5, "observed_at": "2026-09-04 12:00:00" },
    "hourly": [...],
    "daily": [...]
  },
  "meta": {
    "missing_sections": ["pollen", "uv"],
    "available_sections": ["current", "hourly", "daily"]
  }
}
```

## Koordinaten-Rundung

Koordinaten werden bei der Cache-Suche auf 4 Nachkommastellen gerundet (ca. 11m Genauigkeit). Dies ist ausreichend für städtische Wetterdaten und verhindert Cache-Miss bei minimal unterschiedlichen Koordinaten.

## Admin-Cache-Verwaltung

Im Admin Dashboard (`/api/v2/admin/external-data-cache`) können Cache-Einträge eingesehen, nach Datentyp gefiltert und einzeln oder gesamt gelöscht werden.
