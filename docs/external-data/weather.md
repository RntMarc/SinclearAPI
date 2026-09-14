# Wetter-Endpoint

`GET /api/v2/external-data/weather`

## Parameter

| Name | In | Typ | Pflicht | Beschreibung |
|------|----|-----|---------|--------------|
| `city_slug` | query | string | bedingt | City-Slug (z.B. `berlin`) |
| `lat` | query | float | bedingt | Breitengrad |
| `lon` | query | float | bedingt | Längengrad |

Mindestens `city_slug` oder `lat`+`lon` muss angegeben werden.

## Antwort

### `meta`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `source` | string \| null | Datenquelle: `infranode`, `open-meteo`, `mixed` oder `cache` |
| `data_type` | string | Immer `weather` |
| `city_slug` | string \| null | Übergebener City-Slug (null bei Koordinaten) |
| `coordinates` | object \| null | `{lat,lon}` bei Koordinaten-Abfrage |
| `available_sections` | string[] | Verfügbare Abschnitte (`current`, `hourly`, `daily`) |
| `missing_sections` | string[] | Fehlende Abschnitte |
| `retrieved_at` | datetime | Zeitpunkt der Datenabfrage (UTC) |

### `data.current`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `temperature_c` | float \| null | Aktuelle Temperatur in °C |
| `humidity` | float \| null | Relative Luftfeuchtigkeit (%) |
| `apparent_temperature` | float \| null | Gefühlte Temperatur (nur Open-Meteo) |
| `precipitation` | float \| null | Niederschlag in mm |
| `weather_code` | int \| null | WMO-Code |
| `condition` | string \| null | Wetterbeschreibung (nur InfraNode) |
| `cloud_cover` | int \| null | Bewölkung (%) |
| `wind_speed` | float \| null | Windgeschwindigkeit (km/h) |
| `wind_direction` | float \| null | Windrichtung (Grad) |
| `wind_gusts` | float \| null | Böen (km/h) |
| `observed_at` | datetime \| null | Beobachtungszeitpunkt (UTC) |

### `data.hourly`

Array von Stundenvorhersagen (nur Open-Meteo):

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `time` | datetime | Vorhersagezeitpunkt (UTC) |
| `temperature_c` | float \| null | Temperatur in °C |
| `precipitation_probability` | int \| null | Niederschlagswahrscheinlichkeit (%) |
| `weather_code` | int \| null | WMO-Code |
| `wind_speed` | float \| null | Windgeschwindigkeit (km/h) |

### `data.daily`

Array von Tagesvorhersagen (nur Open-Meteo, 3 Tage):

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `date` | date | Datum (YYYY-MM-DD) |
| `weather_code` | int \| null | WMO-Code |
| `temperature_max_c` | float \| null | Maximaltemperatur |
| `temperature_min_c` | float \| null | Minimaltemperatur |
| `precipitation_sum` | float \| null | Niederschlagssumme |
| `precipitation_probability_max` | int \| null | Max. Niederschlagswahrscheinlichkeit |
| `sunrise` | datetime \| null | Sonnenaufgang (UTC) |
| `sunset` | datetime \| null | Sonnenuntergang (UTC) |
| `uv_index_max` | float \| null | Max. UV-Index |
| `wind_speed_max` | float \| null | Max. Windgeschwindigkeit |

### `data._attribution`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `text` | string | Quellen-Angabe (z.B. "Open-Meteo (open-meteo.com)") |
| `url` | string | URL zur Quelle |

## Quellen

- **InfraNode**: Liefert `current` (deutsche Städte)
- **Open-Meteo**: Liefert `current`, `hourly`, `daily` (global)
- Bei InfraNode-Treffer + Open-Meteo werden Daten gemischt (`source: mixed`)
- Die Datenquelle ist in `meta.source` angegeben
- Fällt InfraNode bei einem `city_slug`-Request aus, wird der Open-Meteo-Fallback nur mit reduzierter TTL (60 s) gecached, sodass InfraNode zeitnah erneut abgefragt wird

## Beispiel

```bash
GET /api/v2/external-data/weather?city_slug=berlin
Authorization: Bearer <token>
```

### Antwort-Beispiel

```json
{
  "data": {
    "current": {
      "temperature_c": 18.5,
      "humidity": 65.0,
      "weather_code": 2,
      "condition": "Teilweise bewölkt",
      "wind_speed": 12.3,
      "observed_at": "2026-09-07 14:00:00"
    },
    "hourly": [...],
    "daily": [...],
    "_attribution": {
      "text": "Open-Meteo (open-meteo.com) — Non-commercial use",
      "url": "https://open-meteo.com/en/docs"
    }
  },
  "meta": {
    "source": "mixed",
    "data_type": "weather",
    "city_slug": "berlin",
    "coordinates": null,
    "available_sections": ["current", "hourly", "daily"],
    "missing_sections": [],
    "retrieved_at": "2026-09-07 14:05:00"
  }
}
```

---

# Wetterwarnungen-Endpoint

`GET /api/v2/external-data/weather/warnings`

Liefert aktuelle Wetterwarnungen für einen Standort. InfraNode (deutsche Städte mit Slug) oder BrightSky (global mit lat+lon). Warnungen umfassen Sturm, Regen, Schnee, Nebel, Glitzeis, Hochwasser sowie Spezialwarnungen (Hitze, UV).

## Parameter

| Name | In | Typ | Pflicht | Beschreibung |
|------|----|-----|---------|--------------|
| `city_slug` | query | string | bedingt | City-Slug (z.B. `berlin`) |
| `lat` | query | float | bedingt | Breitengrad |
| `lon` | query | float | bedingt | Längengrad |

Mindestens `city_slug` oder `lat`+`lon` muss angegeben werden.

## Antwort

### `meta`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `source` | string \| null | Datenquelle: `infranode`, `brightsky` oder `cache` |
| `data_type` | string | Immer `weather_warnings` |
| `city_slug` | string \| null | Übergebener City-Slug (null bei Koordinaten) |
| `coordinates` | object \| null | `{lat,lon}` bei Koordinaten-Abfrage |
| `available_sections` | string[] | Verfügbare Abschnitte (`warnings`, `special_warnings`) |
| `missing_sections` | string[] | Fehlende Abschnitte |
| `retrieved_at` | datetime | Zeitpunkt der Datenabfrage (UTC) |

### `data.warnings`

Array von Warnungen:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `event` | string | Warnungsereignis (z.B. "STARKREGEN", "HEAVY RAIN") |
| `level` | int | Schweregrad (0-4: 0=keine, 1=minor, 2=moderate, 3=severe, 4=extreme) |
| `headline` | string | Warnungstitel |
| `start` | datetime \| null | Beginn der Warnung (UTC) |
| `end` | datetime \| null | Ende der Warnung (UTC) |

### `data.special_warnings`

Array von Spezialwarnungen (Hitze, UV) - gleiche Struktur wie `warnings`:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `event` | string | Spezialwarnung (z.B. "HEAT_STRESS", "UV_WARNING") |
| `level` | int | Schweregrad (0-4) |
| `headline` | string | Warnungstitel |
| `start` | datetime \| null | Beginn (UTC) |
| `end` | datetime \| null | Ende (UTC) |

### `data.max_level`

| Typ | Beschreibung |
|-----|--------------|
| int | Maximaler Schweregrad aller Warnungen (0 = keine Warnung) |

### `data.count`

| Typ | Beschreibung |
|-----|--------------|
| int | Anzahl der Warnungen |

### `data._attribution`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `text` | string | Quellen-Angabe |
| `url` | string | URL zur Quelle |

## Quellen

- **InfraNode**: Liefert Warnungen für deutsche Städte (mit `city_slug`)
- **BrightSky**: Liefert Warnungen全球 (mit `lat`+`lon`)
- Fällt InfraNode bei einem `city_slug`-Request aus, wird BrightSky als Fallback verwendet
- BrightSky-Fallback nur mit reduzierter TTL (60 s) gecached, sodass InfraNode zeitnah erneut abgefragt wird
- Die Datenquelle ist in `meta.source` angegeben

## Beispiel

```bash
GET /api/v2/external-data/weather/warnings?lat=52.52&lon=13.405
Authorization: Bearer <token>
```

### Antwort-Beispiel

```json
{
  "data": {
    "warnings": [
      {
        "event": "STARKREGEN",
        "level": 2,
        "headline": "Amtliche WARNUNG vor STARKREGEN",
        "start": "2026-09-07 14:00:00",
        "end": "2026-09-07 20:00:00"
      }
    ],
    "special_warnings": [],
    "max_level": 2,
    "count": 1,
    "_attribution": {
      "text": "Datenbasis: Deutscher Wetterdienst via Bright Sky",
      "url": "https://brightsky.dev"
    }
  },
  "meta": {
    "source": "brightsky",
    "data_type": "weather_warnings",
    "city_slug": null,
    "coordinates": {"lat": 52.52, "lon": 13.405},
    "available_sections": ["warnings", "special_warnings"],
    "missing_sections": [],
    "retrieved_at": "2026-09-07 14:05:00"
  }
}
```
