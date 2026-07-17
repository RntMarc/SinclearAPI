# ÖPNV Integration (Public Transport)

Integration von Fahrplandaten und Fahrtinformationen via [Transitous](https://transitous.org/) (MOTIS 2.x API).

## Datenquelle

[Transitous](https://transitous.org/) ist ein Community-betriebener, offener
Public-Transport-Router, der GTFS-Daten vieler Verkehrsverbünde weltweit
vereint und über eine standardisierte [MOTIS 2.x](https://github.com/motis-project/motis) API bereitstellt.

- **Routing:** `GET https://api.transitous.org/api/v6/plan`
- **Trip-Details:** `GET https://api.transitous.org/api/v6/trip`
- **Geokodierung:** `GET https://api.transitous.org/api/v1/geocode`
- **Health:** `GET https://api.transitous.org/api/v1/health`

**Vorteile gegenüber der eingestellten DB-API (v6.db.transport.rest):**
- Kein API-Key nötig
- Community-betrieben, offene Daten (GTFS)
- Weiterentwickeltes MOTIS-API-Format
- Transitous unterstützt viele Verkehrsverbünde (nicht nur DB)

## Stationen-Cache (TravelStop)

`db-stations` von npm (via unpkg.com) wird heruntergeladen und lokal gecacht
(`GET https://unpkg.com/db-stations@5.0.2/data.ndjson`, NDJSON-Format).

Die Stationssuche erfolgt **zuerst lokal** in `TravelStop`. Wenn kein
Ergebnis lokal gefunden wird und die Datenbank leer ist, wird als Fallback
der Transitous-Geocoding-Endpoint aufgerufen.

## Architektur-Übersicht

```
Client (App/Web)
    │
    ▼
Sinclear API (PHP, Slim 4)
    │
    ├── Eigene Datenbank (gespeicherte Fahrten, Stationen-Cache, Nutzer-Zuordnung)
    │
    └── api.transitous.org (HTTP via Guzzle, MOTIS 2.x /api/v6)
```

## Datenbank-Tabellen

### `TravelPublicTransportJourney`

Eine gesamte Verbindung (Journey) von A nach B, bestehend aus einem oder
mehreren Legs (Abschnitten). Optional mit einem `TravelTrip` verknüpft.

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | UUID v7 |
| `tripId` | `varchar(191) NULL` | FK → `TravelTrip.id` (optional, wenn Teil einer Reise) |
| `creatorId` | `varchar(191) NOT NULL` | FK → `User.id` (wer hat gespeichert) |
| `refreshToken` | `varchar(191) NULL` | Nicht mehr von der API geliefert (MOTIS hat kein Äquivalent) |
| `chosenAt` | `datetime NOT NULL` | Wann der Nutzer diese Verbindung ausgewählt hat (UTC) |
| `createdAt` | `datetime(3) NOT NULL` | |
| `updatedAt` | `datetime(3) NOT NULL` | |

### `TravelPublicTransportLeg`

Ein einzelner Fahrtabschnitt (z.B. MEX16 von Stuttgart Hbf nach Geislingen).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | UUID v7 |
| `journeyId` | `varchar(191) NOT NULL` | FK → `TravelPublicTransportJourney.id` |
| `legIndex` | `tinyint NOT NULL` | Reihenfolge im Journey (0, 1, 2, ...) |
| `mode` | `varchar(50) NOT NULL` | Verkehrsmittel: `train`, `bus`, `walking`, `ferry`, `subway`, `tram`, `suburban`, `regional_rail` |
| `lineName` | `varchar(191) NULL` | Z.B. "MEX16", "ICE 123", `null` bei Fußwegen |
| `lineProduct` | `varchar(50) NULL` | Z.B. `nationalExpress`, `regional_rail`, `bus`, `subway` |
| `originStopId` | `varchar(191) NOT NULL` | FK → `TravelStop.id` |
| `destinationStopId` | `varchar(191) NOT NULL` | FK → `TravelStop.id` |
| `originStopName` | `varchar(255) NOT NULL` | Denormalisiert |
| `destinationStopName` | `varchar(255) NOT NULL` | Denormalisiert |
| `dbTripId` | `varchar(191) NULL` | tripId aus der Transitous/MOTIS-API (z.B. `20260717_14:03_de-DELFI_3288100731`), `null` bei Fußwegen |
| `plannedDeparture` | `datetime NOT NULL` | Soll-Abfahrt (UTC) |
| `plannedArrival` | `datetime NOT NULL` | Soll-Ankunft (UTC) |
| `actualDeparture` | `datetime NULL` | Tatsächliche Abfahrt (UTC, nach Refresh) |
| `actualArrival` | `datetime NULL` | Tatsächliche Ankunft (UTC, nach Refresh) |
| `departureDelay` | `int NULL` | Verspätung Abfahrt in Sekunden |
| `arrivalDelay` | `int NULL` | Verspätung Ankunft in Sekunden |
| `departurePlatform` | `varchar(10) NULL` | Gleis Abfahrt |
| `arrivalPlatform` | `varchar(10) NULL` | Gleis Ankunft |
| `cancelled` | `tinyint(1) DEFAULT 0` | Fahrtabschnitt ausgefallen? |
| `status` | `enum('planned','delayed','cancelled','in_transit','arrived')` | Aktueller Status |
| `rawResponse` | `json NULL` | Letzte API-Antwort (für Debugging/Erweiterungen) |
| `lastCheckedAt` | `datetime(3) NULL` | Letzter Refresh-Zeitstempel |
| `createdAt` | `datetime(3) NOT NULL` | |
| `updatedAt` | `datetime(3) NOT NULL` | |

### `TravelPublicTransportParticipant`

Verknüpfung eines Nutzers mit einem Journey.

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `journeyId` | `varchar(191) NOT NULL` | FK → `TravelPublicTransportJourney.id` |
| `userId` | `varchar(191) NOT NULL` | FK → `User.id` |
| `addedAt` | `datetime(3) NOT NULL` | |

**PK:** `(journeyId, userId)`

### `TravelStop`

Lokaler Cache der Stationen (regelmäßig aktualisierbar).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | Station-ID aus Transitous/MOTIS (z.B. `de-DELFI_de:08111:6115:6:12` für Stuttgart Hbf) |
| `name` | `varchar(255) NOT NULL` | Stationsname |
| `ril100` | `varchar(10) NULL` | RIL100-Kürzel (z.B. `BLS`) – nur aus dem ndjson-Stations-Cache |
| `latitude` | `double NULL` | Geokoordinate |
| `longitude` | `double NULL` | Geokoordinate |
| `weight` | `double NULL` | Relevanz-Gewichtung |
| `products` | `json NULL` | Verfügbare Verkehrsmittel |
| `lastUpdated` | `datetime(3) NOT NULL` | Wann zuletzt aktualisiert |

## MOTIS API Kommunikation (Service-Schicht)

### `src/Services/PublicTransportService.php`

Kapselt alle Aufrufe an Transitous (api.transitous.org) via Guzzle.

**API-Endpunkte:**

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/api/v1/geocode?text={q}&type=STOP&numResults={n}` | Stationssuche (Fallback) |
| `GET` | `/api/v6/plan?fromPlace={id}&toPlace={id}&time={iso}&numItineraries={n}` | Routenplanung A→B |
| `GET` | `/api/v6/trip?tripId={id}` | Details zu einem Trip (für Refresh) |

**MOTIS-Response-Mapping:**

| Transitous/MOTIS | Internes Format |
|---|---|
| `leg.from.stopId` | `origin.id` |
| `leg.from.name` | `origin.name` |
| `leg.to.stopId` | `destination.id` |
| `leg.to.name` | `destination.name` |
| `leg.from.departure` | `departure` (actual) |
| `leg.from.scheduledDeparture` | `plannedWhen` (planned departure) |
| `leg.to.arrival` | `arrival` (actual) |
| `leg.to.scheduledArrival` | `plannedArrival` |
| `leg.mode` | `mode` (lowercased) + `walking` (true bei WALK) |
| `leg.routeShortName` | `line.name` |
| `leg.tripId` | `tripId` |
| `leg.from.track` / `leg.to.track` | `platform` / `arrivalPlatform` |
| `leg.cancelled` / `leg.realTime` | `cancelled` + delay-Berechnung |

**Datenfluss:**

```
PublicTransportService
    ├── searchStations(string $query): array
    │   → Zuerst lokal in TravelStop (Fuzzy-Suche)
    │   → Fallback: GET /api/v1/geocode?text=...&type=STOP
    │
    ├── findJourneys(string $fromId, string $toId, string $departure): array
    │   → GET /api/v6/plan?fromPlace=...&toPlace=...&time=...Z
    │   → Live (kein Caching)
    │
    ├── refreshLeg(string $dbTripId): ?array
    │   → GET /api/v6/trip?tripId=...
    │   → Aktualisiert Verspätungen/Ausfälle
    │
    └── refreshAllStations(): void
        → Ruft Stationen von unpkg.com/db-stations ab (NDJSON)
```

**User-Agent:** Transitous verlangt einen `User-Agent`-Header mit
App-Name/Version/Kontakt: `SinclearBeyond/1.0 (dev@sinclear.com)`.

## API-Endpunkte (öffentlich, JWT-geschützt)

### Stationen / Haltestellen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/stations?query=...` | Stationen suchen (Autocomplete) |
| `POST` | `/public-transport/stations/refresh` | Stationen-Cache updaten (triggert NDJSON-Download) |

`GET /public-transport/stations` sucht **zuerst lokal** in `TravelStop` nach passenden
Stationen (für schnelles Autocomplete). Wenn kein Ergebnis, Fallback auf Transitous Geocode.

### Routenplanung / Verbindungssuche

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/journeys?from={stopId}&to={stopId}&departure={datetime}` | Verbindungen suchen (live von Transitous) |

Query-Parameter:
- `from` (required) – Start-Station-ID
- `to` (required) – Ziel-Station-ID
- `departure` (optional, default: now) – Abfahrtszeit `YYYY-MM-DD HH:MM:SS` UTC
- `results` (optional, default: 5) – Anzahl Ergebnisse

**Kein Caching**, jede Anfrage geht live an die Transitous-API.

Antwortformat:
```json
{
  "data": [
    {
      "type": "journey",
      "legs": [
        {
          "origin": { "id": "de-DELFI_de:08111:6115:6:12", "name": "Stuttgart Hbf (oben)" },
          "destination": { "id": "de-DELFI_de:08111:226:9:26", "name": "Stadtwerke" },
          "departure": "2026-07-17 12:03:00",
          "arrival": "2026-07-17 12:12:00",
          "mode": "regional_rail",
          "line": { "name": "MEX16", "product": "regional_rail" },
          "platform": "12",
          "cancelled": false,
          "tripId": "20260717_14:03_de-DELFI_3288100731"
        }
      ],
      "transfers": 0
    }
  ]
}
```

### Gespeicherte Fahrten verwalten

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/public-transport/journeys` | Eine Fahrt speichern (aus Suchergebnis ausgewählt) |
| `GET` | `/public-transport/journeys` | Eigene Fahrten listen (gefiltert nach Nutzer/Reise) |
| `GET` | `/public-transport/journeys/{id}` | Fahrt-Details (mit Legs und Teilnehmern) |
| `DELETE` | `/public-transport/journeys/{id}` | Fahrt löschen |
| `POST` | `/public-transport/journeys/{id}/participants` | Teilnehmer hinzufügen |
| `DELETE` | `/public-transport/journeys/{id}/participants/{userId}` | Teilnehmer entfernen |

#### `POST /public-transport/journeys`

Request-Body:
```json
{
  "tripId": null,
  "journeyData": {
    "legs": [
      {
        "origin": { "id": "de-DELFI_de:08111:6115:6:12", "name": "Stuttgart Hbf (oben)" },
        "destination": { "id": "de-DELFI_de:08111:226:9:26", "name": "Stadtwerke" },
        "departure": "2026-07-17 12:03:00",
        "arrival": "2026-07-17 12:12:00",
        "mode": "regional_rail",
        "line": { "name": "MEX16", "product": "regional_rail" },
        "tripId": "20260717_14:03_de-DELFI_3288100731",
        "platform": "12"
      }
    ]
  },
  "participantIds": ["user-id-1", "user-id-2"]
}
```

- `tripId`: UUID einer `TravelTrip` (optional, `null` wenn standalone)
- `participantIds`: Weitere Teilnehmer (optional). Der speichernde Nutzer wird **immer automatisch** als Teilnehmer hinzugefügt.

#### `GET /public-transport/journeys`

Query-Parameter:
- `tripId` (optional) – Nur Fahrten zu einer bestimmten Reise
- `includeLegs` (optional, default: `true`) – Legs direkt mitsenden
- `page`, `limit` – Pagination

**Autorisierung:**
- Ohne Filter: Nur eigene Fahrten
- Mit `tripId`: Nur wenn der Nutzer Teilnehmer dieser Reise ist

### Echtzeit-Updates

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/public-transport/journeys/{id}/refresh` | Einzelne Fahrt aktualisieren |
| `POST` | `/public-transport/journeys/refresh` | Alle nicht-aktualisierten Fahrten aktualisieren |

`POST /public-transport/journeys/{id}/refresh`:
- Ruft für jeden Leg mit `dbTripId` die aktuelle Trip-Info ab (`GET /api/v6/trip?tripId=...`)
- Aktualisiert `actualDeparture`, `actualArrival`, `delay`, `platform`, `cancelled`, `status`
- Gibt den aktualisierten Journey zurück

`POST /public-transport/journeys/refresh`:
- Iteriert über Legs, deren `lastCheckedAt` älter als ein konfigurierbares Intervall ist
- Dies ist der Endpunkt, den ein Cron-Job regelmäßig aufrufen kann

## Zeitformat

Die Transitous/MOTIS API liefert ISO 8601 UTC mit `Z`-Suffix
(z.B. `2026-07-17T12:03:00Z`). Die Service-Schicht konvertiert vor dem Speichern
in das interne UTC-Format `YYYY-MM-DD HH:MM:SS`.

**Eingabe für die API:** Das `time`-Parameter für `/api/v6/plan` muss als
ISO 8601 UTC mit `Z`-Suffix gesendet werden (z.B. `2026-07-17T12:00:00Z`).

## Cron-Jobs (MySQL Events)

Geplante DB Events:

| Event | Intervall | Beschreibung |
|-------|-----------|-------------|
| `refresh_public_transport_journeys` | Alle 15 Minuten | Ruft für alle abgelaufenen Legs aktuelle Verspätungen ab |
| `refresh_public_transport_stations` | Täglich (04:00) | Aktualisiert den Stationen-Cache |
| `clean_old_public_transport_data` | Täglich (04:30) | Löscht Fahrten älter als 90 Tage |

> Die Refresh-Events rufen **HTTP-Endpunkte** der API auf (da die
> Business-Logik im PHP-Service liegt). Alternativ kann ein externer Cron-Job
> (z.B. via `crontab -e` oder systemd-timer) die API-Endpunkte aufrufen:
> ```
> */15 * * * * curl -X POST https://api.sinclear.de/api/v2/public-transport/journeys/refresh
> ```

## Stations-IDs

Die `TravelStop.id` entspricht der `id` aus dem `db-stations` NDJSON-Datensatz
(z.B. `de-DELFI_de:08111:6115:6:12` für Stuttgart Hbf Bahnsteig 11+12).
Abhängig von der Quelle (NDJSON vs. Transitous-Geocode) können die IDs
unterschiedlich sein (DELFI-Format mit/ohne Prefix).

## Attribution

Bei Nutzung der Transitous-API ist ein User-Agent-Header mit App-Name,
Version und Kontakt-Informationen erforderlich.
Die Datenquellen von Transitous sind unter https://transitous.org/sources/ einsehbar.
