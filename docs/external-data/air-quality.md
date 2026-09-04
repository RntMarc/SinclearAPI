# Luftqualitäts-Endpoint

`GET /api/v2/external-data/air-quality`

## Parameter

| Name | In | Typ | Pflicht | Beschreibung |
|------|----|-----|---------|--------------|
| `city_slug` | query | string | bedingt | City-Slug (z.B. `berlin`) |
| `lat` | query | float | bedingt | Breitengrad |
| `lon` | query | float | bedingt | Längengrad |

Mindestens `city_slug` oder `lat`+`lon` muss angegeben werden.

## Antwort

### `data`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `pm10` | float \| null | PM10 in µg/m³ |
| `pm25` | float \| null | PM2.5 in µg/m³ |
| `no2` | float \| null | NO₂ in µg/m³ |
| `o3` | float \| null | O₃ in µg/m³ |
| `so2` | float \| null | SO₂ in µg/m³ |
| `station_id` | string \| null | Messstation (nur InfraNode) |
| `observed_at` | datetime \| null | Beobachtungszeitpunkt (UTC) |

### `_attribution`

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `text` | string | Quellenangabe |
| `url` | string | Lizenz-URL |

## Quellen

- **InfraNode**: Deutsche Luftqualitäts-Messstationen (UBA)
- **Open-Meteo**: Globaler Fallback (CAMS-Daten)

## Beispiel

```bash
GET /api/v2/external-data/air-quality?lat=52.52&lon=13.405
Authorization: Bearer <token>
```
