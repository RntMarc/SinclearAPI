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

## Quellen

- **InfraNode**: Liefert `current` (deutsche Städte)
- **Open-Meteo**: Liefert `current`, `hourly`, `daily` (global)
- Bei InfraNode-Treffer + Open-Meteo werden Daten gemischt (`source: mixed`)

## Beispiel

```bash
GET /api/v2/external-data/weather?city_slug=berlin
Authorization: Bearer <token>
```
