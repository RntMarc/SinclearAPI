# ÖPNV / Deutsche Bahn Integration (Public Transport)

Integration von Fahrplandaten und Fahrtinformationen aus der API der Deutschen Bahn
via [`v6.db.transport.rest`](https://v6.db.transport.rest/).

## Architektur-Übersicht

```
Client (App/Web)
    │
    ▼
Sinclear API (PHP, Slim 4)
    │
    ├── Eigene Datenbank (gespeicherte Fahrten, Stationen-Cache, Nutzer-Zuordnung)
    │
    └── v6.db.transport.rest (HTTP via Guzzle)
            │
            └── DB Vendo/Movas API (gleiches Backend wie DB Navigator)
```

**Warum `v6.db.transport.rest`?**
- Einzige verfügbare API mit **Routenplanung (A→B)** für das deutsche Schienennetz
- Kein API-Key nötig (aber 60 req/min Limit)
- Liefert Echtzeitdaten (Verspätungen, Ausfälle, Gleisänderungen)
- Wird von vielen Projekten produktiv genutzt

## Datenbank-Tabellen

### `TravelPublicTransportJourney`

Eine gesamte Verbindung (Journey) von A nach B, bestehend aus einem oder
mehreren Legs (Abschnitten). Optional mit einem `TravelTrip` verknüpft.

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | UUID v7 |
| `tripId` | `varchar(191) NULL` | FK → `TravelTrip.id` (optional, wenn Teil einer Reise) |
| `creatorId` | `varchar(191) NOT NULL` | FK → `User.id` (wer hat gespeichert) |
| `refreshToken` | `varchar(191) NULL` | Von der DB-API für Pagination (frühere/spätere Verbindungen) |
| `chosenAt` | `datetime NOT NULL` | Wann der Nutzer diese Verbindung ausgewählt hat (UTC) |
| `createdAt` | `datetime(3) NOT NULL` | |
| `updatedAt` | `datetime(3) NOT NULL` | |

### `TravelPublicTransportLeg`

Ein einzelner Fahrtabschnitt (z.B. ICE 123 von Frankfurt Hbf nach München Hbf).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | UUID v7 |
| `journeyId` | `varchar(191) NOT NULL` | FK → `TravelPublicTransportJourney.id` |
| `legIndex` | `tinyint NOT NULL` | Reihenfolge im Journey (0, 1, 2, ...) |
| `mode` | `varchar(50) NOT NULL` | Verkehrsmittel: `train`, `bus`, `walking`, `ferry`, `subway`, `tram`, `suburban` |
| `lineName` | `varchar(191) NULL` | Z.B. "ICE 123", "Bus 248", `null` bei Fußwegen |
| `lineProduct` | `varchar(50) NULL` | Z.B. `nationalExpress`, `regional`, `bus`, `suburban` |
| `originStopId` | `varchar(191) NOT NULL` | FK → `TravelStop.id` |
| `destinationStopId` | `varchar(191) NOT NULL` | FK → `TravelStop.id` |
| `originStopName` | `varchar(255) NOT NULL` | Denormalisiert |
| `destinationStopName` | `varchar(255) NOT NULL` | Denormalisiert |
| `dbTripId` | `varchar(191) NULL` | tripId aus der DB-API (z.B. `1|245684|0|80|27032019`), `null` bei Fußwegen |
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

Lokaler Cache der DB-Stationen (regelmäßig aktualisierbar).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `varchar(191) PK` | Station-ID aus der DB-API (z.B. `8011113` für Berlin Südkreuz) |
| `name` | `varchar(255) NOT NULL` | Stationsname |
| `ril100` | `varchar(10) NULL` | RIL100-Kürzel (z.B. `BLS`) |
| `latitude` | `double NULL` | Geokoordinate |
| `longitude` | `double NULL` | Geokoordinate |
| `weight` | `double NULL` | Relevanz-Gewichtung |
| `products` | `json NULL` | Verfügbare Verkehrsmittel |
| `lastUpdated` | `datetime(3) NOT NULL` | Wann zuletzt von der DB-API aktualisiert |

## DB-API Kommunikation (Service-Schicht)

### `src/Services/PublicTransportService.php`

Kapselt alle Aufrufe an `v6.db.transport.rest` via Guzzle.

**API-Endpunkte von v6.db.transport.rest:**

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/locations?query={q}&poi=false&addresses=false` | Stations-/Haltestellensuche |
| `GET` | `/journeys?from={id}&to={id}&departure={datetime}&results={n}` | Routenplanung A→B |
| `GET` | `/trips/{tripId}?stopovers=true` | Details zu einem Trip (für Refresh) |

**Datenfluss:**

```
PublicTransportService
    ├── searchStations(string $query): array
    │   → GET /locations?query=...&poi=false&addresses=false
    │   → Lokal cachen in TravelStop
    │
    ├── findJourneys(string $fromId, string $toId, string $departure): array
    │   → GET /journeys?from=...&to=...&departure=...
    │   → Live (kein Caching)
    │
    ├── refreshLeg(string $dbTripId): ?array
    │   → GET /trips/{tripId}?stopovers=true
    │   → Aktualisiert Verspätungen/Ausfälle
    │
    └── refreshAllStations(): void
        → Ruft Stationen-Set von db-stations ab und aktualisiert TravelStop
```

**Rate-Limiting:**
- v6.db.transport.rest hat ~60 req/min
- Ein `refreshAllStations()`-Durchlauf könnte hunderte Requests erzeugen → **muss** mit
  entsprechenden Verzögerungen/Throttling laufen (z.B. 1 Request pro Sekunde).
- Ein `refreshLeg()` ist ein einzelner Request, unkritisch.

## API-Endpunkte (öffentlich, JWT-geschützt)

### Stationen / Haltestellen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/stations?query=...` | Stationen suchen (Autocomplete) |
| `POST` | `/public-transport/stations/refresh` | Stationen-Cache updaten (triggert asynchrone Aktualisierung) |

`GET /public-transport/stations` sucht **zuerst lokal** in `TravelStop` nach passenden
Stationen (für schnelles Autocomplete). Wenn kein Ergebnis, Fallback auf Live-API.
Optional: `?forceLive=true` überspringt den lokalen Cache.

### Routenplanung / Verbindungssuche

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/journeys?from={stopId}&to={stopId}&departure={datetime}` | Verbindungen suchen (live von DB-API) |

Query-Parameter:
- `from` (required) – Start-Station-ID
- `to` (required) – Ziel-Station-ID
- `departure` (optional, default: now) – Abfahrtszeit `YYYY-MM-DD HH:MM:SS` UTC
- `arrival` (optional) – Ankunftszeit (statt Abfahrt)
- `results` (optional, default: 5) – Anzahl Ergebnisse

**Kein Caching**, jede Anfrage geht live an die DB-API.

Antwortformat:
```json
{
  "data": [
    {
      "type": "journey",
      "legs": [
        {
          "origin": { "id": "8000105", "name": "Frankfurt(Main)Hbf" },
          "destination": { "id": "8000261", "name": "München Hbf" },
          "departure": "2026-07-14 14:30:00",
          "arrival": "2026-07-14 19:06:00",
          "mode": "train",
          "line": { "name": "ICE 123", "product": "nationalExpress" },
          "platform": "12",
          "cancelled": false
        }
      ],
      "duration": 276,
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
        "origin": { "id": "8000105", "name": "Frankfurt(Main)Hbf" },
        "destination": { "id": "8000261", "name": "München Hbf" },
        "departure": "2026-07-14 14:30:00",
        "arrival": "2026-07-14 19:06:00",
        "mode": "train",
        "line": { "name": "ICE 123", "product": "nationalExpress" },
        "tripId": "1|245684|0|80|27032019",
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
- `userId` (optional) – Nur Fahrten eines bestimmten Teilnehmers
- `includeLegs` (optional, default: `true`) – Legs direkt mitsenden
- `page`, `limit` – Pagination

**Autorisierung:**
- Ohne Filter: Nur eigene Fahrten
- Mit `tripId`: Nur wenn der Nutzer Teilnehmer dieser Reise ist
- Mit `userId`: Nur der eigene `userId` (kein fremder Zugriff)

### Echtzeit-Updates

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/public-transport/journeys/{id}/refresh` | Einzelne Fahrt aktualisieren |
| `POST` | `/public-transport/journeys/refresh` | Alle eigenen (oder alle nicht-aktualisierten) Fahrten aktualisieren |

`POST /public-transport/journeys/{id}/refresh`:
- Ruft für jeden Leg mit `dbTripId` die aktuelle Trip-Info ab
- Aktualisiert `actualDeparture`, `actualArrival`, `delay`, `platform`, `cancelled`, `status`
- Gibt den aktualisierten Journey zurück

`POST /public-transport/journeys/refresh`:
- Iteriert über Legs, deren `lastCheckedAt` älter als ein konfigurierbares Intervall ist
- Dies ist der Endpunkt, den ein Cron-Job regelmäßig aufrufen kann

## Implementierungs-Reihenfolge

### Phase 1: Basis (Datenbank + Service)
1. SQL-Migration: `TravelStop`, `TravelPublicTransportJourney`, `TravelPublicTransportLeg`, `TravelPublicTransportParticipant`
2. `StopRepository` – CRUD für TravelStop
3. `PublicTransportJourneyRepository` – CRUD für Journey + Legs + Participants
4. `PublicTransportService` – Kommunikation mit v6.db.transport.rest inkl. Mapping

### Phase 2: Controller + Routes
5. `PublicTransportController` – Alle API-Endpunkte
6. Routen in `config/routes.php` registrieren
7. Dependency Wiring in `config/dependencies.php`

### Phase 3: Stations-Cache
8. `POST /public-transport/stations/refresh` – Initialbefüllung und regelmäßiges Update
9. CLI-Script für Cron-Job (optional)

### Phase 4: Echtzeit-Updates
10. `POST /public-transport/journeys/{id}/refresh` – Einzelfahrt-Update
11. `POST /public-transport/journeys/refresh` – Batch-Update (für Cron-Job)

### Phase 5: Dokumentation + OpenAPI
12. `docs/public-transport/readme.md` (dieses Dokument)
13. `openapi.yaml` aktualisieren
14. `.htaccess` prüfen

## Cron-Jobs (MySQL Events)

Geplante DB Events, analog zu bestehenden Cleanup-Events:

| Event | Intervall | Beschreibung |
|-------|-----------|-------------|
| `refresh_public_transport_journeys` | Alle 15 Minuten | Ruft für alle abgelaufenen Legs aktuelle Verspätungen ab |
| `refresh_public_transport_stations` | Täglich (04:00) | Aktualisiert den Stationen-Cache |
| `clean_old_public_transport_data` | Täglich (04:30) | Löscht Fahrten älter als 90 Tage |

> **Hinweis:** Die Refresh-Events rufen **HTTP-Endpunkte** der API auf (da die
> Business-Logik im PHP-Service liegt). Alternativ kann ein externer Cron-Job
> (z.B. via `crontab -e` oder systemd-timer) die API-Endpunkte aufrufen:
> ```
> */15 * * * * curl -X POST https://api.sinclear.de/api/v2/public-transport/journeys/refresh
> ```

## Wichtige Hinweise

### Rate Limits
Die `v6.db.transport.rest` API hat ein Limit von ~60 Requests pro Minute pro IPv4.
Ein `stations/refresh` muss daher mit ausreichendem Abstand zwischen Requests erfolgen.
Ein `journeys/search` oder `journeys/{id}/refresh` ist ein einzelner Request und unkritisch.

### Zeitformat
Alle Zeitangaben in der API folgen dem UTC-Standard: `YYYY-MM-DD HH:MM:SS`.
Die DB-API (v6.db.transport.rest) liefert ISO 8601 mit Zeitzone (z.B.
`2026-07-14T14:30:00+02:00`). Die Service-Schicht konvertiert vor dem Speichern
nach UTC.

### Stations-IDs
Die `TravelStop.id` entspricht der `id` aus der `v6.db.transport.rest` API
(z.B. `8011113` für Berlin Südkreuz). Dies können sowohl EVA-Nummern als auch
interne IDs sein.
