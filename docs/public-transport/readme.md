# Public Transport (Transitious Integration)

Integration von ÖPNV-Daten über die [Transitious](https://transitous.org/) API
(MOTIS 2 API). Transitious ist ein gemeinnütziger, datenschutzfreundlicher
ÖPNV-Routing-Service basierend auf Open-Source-Daten.

## Architektur-Übersicht

```
Client (App/Web)
    │
    ▼
Sinclear API (PHP 8.4, Slim 4)
    │
    ├── Eigene Datenbank (MySQL)
    │   ├── PtStation (lokaler Cache)
    │   ├── PtJourney (gespeicherte Verbindungen)
    │   ├── PtLeg (Fahrtabschnitte)
    │   └── PtParticipant (Teilnehmer)
    │
    └── Transitious API (HTTP via Guzzle)
        ├── /api/v1/geocode (Stationssuche)
        ├── /api/v6/plan (Routenplanung)
        ├── /api/v6/stoptimes (Abfahrtsplan)
        └── /api/v6/trip (Trip-Details)
```

## API-Endpunkte

### Stationen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/stations?q=...` | Stationen suchen (Autocomplete) |
| `GET` | `/public-transport/stations/{id}/departures` | Abfahrtsplan |

### Verbindungen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/journeys?from=...&to=...` | Verbindung suchen |
| `POST` | `/public-transport/journeys` | Verbindung speichern |
| `GET` | `/public-transport/journeys/list` | Eigene Verbindungen |
| `GET` | `/public-transport/journeys/{id}` | Verbindungsdetails |
| `PATCH` | `/public-transport/journeys/{id}` | Verbindung aktualisieren |
| `DELETE` | `/public-transport/journeys/{id}` | Verbindung löschen |
| `POST` | `/public-transport/journeys/{id}/refresh` | Verbindung aktualisieren (Echtzeit) |
| `POST` | `/public-transport/journeys/{id}/participants` | Teilnehmer hinzufügen |
| `DELETE` | `/public-transport/journeys/{id}/participants/{userId}` | Teilnehmer entfernen |

### Legs

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PATCH` | `/public-transport/legs/{id}` | Leg aktualisieren (Verknüpfung/Trip-ID ändern) |

## Beispiele

### Station suchen

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://api.sinclear.de/api/v2/public-transport/stations?q=Frankfurt"
```

Response:
```json
{
  "data": [
    {
      "id": "8000105",
      "name": "Frankfurt(Main)Hbf",
      "latitude": 50.1109,
      "longitude": 8.6821
    }
  ]
}
```

### Verbindung suchen

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://api.sinclear.de/api/v2/public-transport/journeys?from=8000105&to=8000041&departure=2026-07-17 10:00:00"
```

Response:
```json
{
  "data": [
    {
      "duration": 276,
      "transfers": 0,
      "departureTime": "2026-07-17 10:00:00",
      "arrivalTime": "2026-07-17 14:36:00",
      "legs": [
        {
          "mode": "RAIL",
          "lineName": "ICE 123",
          "fromStationName": "Frankfurt(Main)Hbf",
          "toStationName": "München Hbf",
          "plannedDeparture": "2026-07-17 10:00:00",
          "plannedArrival": "2026-07-17 14:36:00"
        }
      ]
    }
  ]
}
```

### Verbindung speichern

```bash
curl -X POST -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  "https://api.sinclear.de/api/v2/public-transport/journeys" \
  -d '{
    "fromStationId": "8000105",
    "fromStationName": "Frankfurt(Main)Hbf",
    "toStationId": "8000041",
    "toStationName": "München Hbf",
    "departureTime": "2026-07-17 10:00:00",
    "arrivalTime": "2026-07-17 14:36:00",
    "duration": 276,
    "transfers": 0,
    "legs": [...]
  }'
```

## Datenbank-Tabellen

### `PtStation`
Lokaler Cache für Stationen.

### `PtJourney`
Gespeicherte Verbindung (A→B).

### `PtLeg`
Einzelner Fahrtabschnitt.

### `PtParticipant`
Teilnehmer-Verknüpfung.

## Technische Hinweise

### Automatischer Echtzeit-Refresh (Cron)
Alle 5 Minuten aktualisiert der Cron-Task `pt_refresh_stale_legs` automatisch
veraltete Legs mit aktuellen Daten von Transitious. Details siehe `docs/CRON.md`.

### Transitious API
- **Kein API-Key** nötig
- **User-Agent Header** erforderlich: `SinclearBeyondAPI/2.0 (https://sinclear.app)`
- **Open-Source Lizenz** erforderlich
- Attribution: Link zu https://transitous.org/sources/

### Zeitformat
- Transitious liefert ISO 8601: `2026-07-17T10:00:00Z`
- API speichert/liefert UTC: `2026-07-17 10:00:00`

### SQL-Migration
Neue Tabellen in `events/pt_schema.sql` erstellen.
