# Verfügbare Datentypen

`GET /api/v2/external-data/types`

Gibt eine Übersicht aller unterstützten external-data Datentypen zurück.

## Antwort

```json
{
  "data": {
    "weather": {
      "label": "Wetter",
      "label_en": "Weather",
      "sections": ["current", "hourly", "daily"],
      "sources": ["infranode", "open-meteo"],
      "requires": "city_slug oder lat+lon"
    },
    "weather_warnings": {
      "label": "Wetterwarnungen",
      "label_en": "Weather warnings",
      "sections": ["warnings"],
      "sources": ["infranode"],
      "requires": "city_slug"
    },
    "pollen_uv": {
      "label": "Pollenbelastung & UV-Index",
      "label_en": "Pollen & UV index",
      "sections": ["pollen", "uv"],
      "sources": ["infranode", "open-meteo"],
      "requires": "city_slug oder lat+lon"
    },
    "air_quality": {
      "label": "Luftqualität",
      "label_en": "Air quality",
      "sections": ["current"],
      "sources": ["infranode", "open-meteo"],
      "requires": "city_slug oder lat+lon"
    }
  }
}
```

## Datentypen

### `weather`
- **Abschnitte:** `current` (aktuell), `hourly` (stündlich), `daily` (täglich)
- **Quellen:** InfraNode (DE), Open-Meteo (global)
- **Beispiel:** `GET /external-data/weather?city_slug=berlin`

### `weather_warnings`
- **Abschnitte:** `warnings` (Warnungen)
- **Quellen:** Nur InfraNode (DE)
- **Beispiel:** `GET /external-data/weather/warnings?city_slug=berlin`

### `pollen_uv`
- **Abschnitte:** `pollen` (Pollenbelastung), `uv` (UV-Index)
- **Quellen:** InfraNode (Pollen + UV, DE), Open-Meteo (nur UV, global)
- **Beispiel:** `GET /external-data/pollen-uv?city_slug=berlin`

### `air_quality`
- **Abschnitte:** `current` (aktuelle Messwerte)
- **Quellen:** InfraNode (DE), Open-Meteo (global)
- **Beispiel:** `GET /external-data/air-quality?lat=52.52&lon=13.405`
