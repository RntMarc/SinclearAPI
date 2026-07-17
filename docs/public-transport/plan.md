# Public Transport Plan - Transitious Integration

> **Status:** Entwurf
> **Datum:** 2026-07-16
> **API:** [Transitous](https://transitous.org/) (MOTIS 2 API)

## 1. Überblick

Vollständige Neuimplementierung der ÖPNV-Integration basierend auf der Transitious API
(community-betriebener, datenschutzfreundlicher ÖPNV-Routing-Service).

### 1.1 Technische Eckdaten

| Eigenschaft | Wert |
|-------------|------|
| API-Base-URL | `https://api.transitous.org/api/` |
| Authentifizierung | Kein API-Key nötig |
| Erforderlich | `User-Agent` Header (App-Name, Version, Kontakt) |
| Datenformat | JSON |
| Spezifikation | OpenAPI 3.1.0 (MOTIS 2) |
| Lizenz | Open-Source erforderlich |
| Rate-Limits | Keine expliziten Limits, Contact bei hoher Last empfohlen |

### 1.2 Verfügbare Transitious-Endpunkte

| Endpoint | Version | Beschreibung |
|----------|---------|--------------|
| `/api/v6/plan` | v6 | Routenplanung A→B (Hauptfeature) |
| `/api/v5/stoptimes` | v5 | Abfahrten/Ankünfte an einer Station |
| `/api/v5/trip` | v5 | Detail-Informationen zu einem Trip |
| `/api/v1/geocode` | v1 | Adress-/Stations-Suche (Autocomplete) |
| `/api/v1/reverse-geocode` | v1 | Koordinaten → Adresse |
| `/api/v1/map/stops` | v1 | Stops in einem Kartenausschnitt |

## 2. Gewünschte Funktionen

### 2.1 Stationssuche (Geocoding)

**Ziel:** Nutzer kann Haltestellen/Einrichtungen nach Name suchen und erhält eine Liste
mit Vorschlägen inkl. Koordinaten und ID.

**Transitious-Endpoint:** `GET /api/v1/geocode`

**Parameter:**
- `q` (required): Suchbegriff (z.B. "Frankfurt Hbf")
- `lang` (optional): Sprache (default: `de`)

**Antwort-Mapping:**
```
Transitious Response → API Response
- id               → id
- name             → name
- lat, lon         → latitude, longitude
- platforms         → platforms (optional)
```

### 2.2 Abfahrtsplan einer Station

**Ziel:** Für eine gegebene Station die nächsten Abfahrten anzeigen.

**Transitious-Endpoint:** `GET /api/v5/stoptimes`

**Parameter:**
- `stopId` (required): Station-ID (aus Geocoding)
- `time` (optional): Zeitpunkt (default: jetzt)
- `n` (required): Anzahl Abfahrten (z.B. 20)
- `arriveBy` (optional): `false` = Abfahrten, `true` = Ankünfte
- `language` (optional): Sprache

**Antwort-Mapping:**
```
Transitious Response → API Response
- stopTimes[].stop.name     → stationName
- stopTimes[].trip.label    → lineName
- stopTimes[].trip.mode     → mode
- stopTimes[].time          → departureTime (ISO → UTC konvertieren)
- stopTimes[].stop.platform → platform
```

### 2.3 Verbindungssuche (Routing)

**Ziel:** Optimale Verbindungen von A nach B berechnen.

**Transitious-Endpoint:** `GET /api/v6/plan`

**Parameter:**
- `fromPlace` (required): Start-Koordinaten (`lat,lon`) oder Stop-ID
- `toPlace` (required): Ziel-Koordinaten (`lat,lon`) oder Stop-ID
- `time` (optional): Abfahrts-/Ankunftszeit (ISO 8601)
- `arriveBy` (optional): `false` = Abfahrt, `true` = Ankunft
- `numItineraries` (optional): Anzahl Verbindungsvorschläge (default: 5)
- `maxTransfers` (optional): Max. Umstiege
- `transitModes` (optional): Erlaubte Verkehrsmittel
- `searchWindow` (optional): Suchfenster in Sekunden (default: 900)
- `pageCursor` (optional): Für Pagination

**Antwort-Mapping:**
```
Transitious Response → API Response
- itineraries[].legs[]           → legs[]
- itineraries[].duration         → duration (Sekunden)
- itineraries[].transfers        → transfers
- itineraries[].startTime        → departureTime
- itineraries[].endTime          → arrivalTime
- legs[].from.name               → origin.name
- legs[].to.name                 → destination.name
- legs[].startTime               → departure
- legs[].endTime                 → arrival
- legs[].mode                    → mode (WALK, BUS, RAIL, etc.)
- legs[].route                   → lineName
- legs[].tripId                  → tripId (für Refresh)
- legs[].stopovedepartureDelay   → departureDelay
- legs[].realTimeState           → realTimeState
```

### 2.4 Gespeicherte Verbindungen

**Ziel:** Vom Nutzer ausgewählte Verbindungen lokal speichern und verwalten.

**Funktionen:**
- Verbindung speichern (aus Suchergebnis)
- Eigene Verbindungen auflisten
- Verbindungsdetails abrufen
- Verbindung löschen
- Teilnehmer hinzufügen/entfernen

### 2.5 Echtzeit-Updates

**Ziel:** Gespeicherte Verbindungen mit aktuellen Daten aktualisieren.

**Transitious-Endpoint:** `GET /api/v5/trip`

**Parameter:**
- `tripId` (required): Trip-ID (aus Plan-Antwort)
- `time` (optional): Zeitpunkt für den Trip

## 3. Beispiel-Flows

### Flow 1: Station suchen

```
1. Nutzer tippt "Frank" in Suchfeld
2. Client → GET /api/v2/public-transport/stations?q=Frank
3. API → GET https://api.transitous.org/api/v1/geocode?q=Frank&lang=de
4. API ← [{ id: "8000105", name: "Frankfurt(Main)Hbf", lat: 50.1109, lon: 8.6821 }, ...]
5. Client ← [{ id: "8000105", name: "Frankfurt(Main)Hbf", latitude: 50.1109, longitude: 8.6821 }, ...]
```

### Flow 2: Verbindung suchen

```
1. Nutzer wählt Start: Frankfurt Hbf (8000105) und Ziel: München Hbf (8000041)
2. Client → GET /api/v2/public-transport/journeys?from=8000105&to=8000041&departure=2026-07-17T10:00:00&results=3
3. API → GET https://api.transitous.org/api/v6/plan?fromPlace=8000105&toPlace=8000041&time=2026-07-17T10:00:00&numItineraries=3
4. API transformiert Antwort
5. Client ← [{ legs: [...], duration: 276, transfers: 0 }, ...]
```

### Flow 3: Abfahrtsplan abrufen

```
1. Nutzer fragt Abfahrten an Station "Berlin Hbf" ab
2. Client → GET /api/v2/public-transport/stations/8000320/departures?limit=10
3. API → GET https://api.transitous.org/api/v5/stoptimes?stopId=8000320&n=10&arriveBy=false
4. API ← [{ trip: { label: "ICE 123" }, time: "2026-07-17T14:30:00Z", stop: { platform: "1" } }, ...]
5. Client ← [{ lineName: "ICE 123", departure: "2026-07-17 14:30:00", platform: "1" }, ...]
```

### Flow 4: Verbindung speichern

```
1. Nutzer wählt eine Verbindung aus der Suche
2. Client → POST /api/v2/public-transport/journeys
   Body: {
     "fromStation": "8000105",
     "toStation": "8000041",
     "departure": "2026-07-17T10:00:00",
     "arrival": "2026-07-17T14:36:00",
     "legs": [...],  // Komplette Legs-Daten aus Suchergebnis
     "participantIds": ["user-uuid-1"]
   }
3. API speichert in PtJourney + PtLeg + PtParticipant
4. Client ← 201 Created { id: "journey-uuid", ... }
```

### Flow 5: Gespeicherte Verbindung aktualisieren

```
1. Client → POST /api/v2/public-transport/journeys/{id}/refresh
2. API ruft für jeden Leg mit tripId die aktuellen Daten ab
3. API → GET https://api.transitous.org/api/v5/trip?tripId=xxx&time=...
4. API aktualisiert Verspätungen, Gleise, Ausfälle
5. Client ← aktualisierte Verbindung mit Echtzeitdaten
```

## 4. Datenbank-Schema (neu)

### 4.1 `PtStation`

Lokaler Cache für Stationen (optional, für schnelle Suche).

```sql
CREATE TABLE PtStation (
  id VARCHAR(191) NOT NULL,
  name VARCHAR(255) NOT NULL,
  latitude DOUBLE NULL,
  longitude DOUBLE NULL,
  lastUpdated DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_station_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.2 `PtJourney`

Gespeicherte Verbindung (A→B).

```sql
CREATE TABLE PtJourney (
  id VARCHAR(191) NOT NULL,
  tripId VARCHAR(191) NULL,           -- FK → TravelTrip.id (optional)
  creatorId VARCHAR(191) NOT NULL,    -- FK → User.id
  fromStationId VARCHAR(191) NOT NULL,
  fromStationName VARCHAR(255) NOT NULL,
  toStationId VARCHAR(191) NOT NULL,
  toStationName VARCHAR(255) NOT NULL,
  departureTime DATETIME NOT NULL,
  arrivalTime DATETIME NOT NULL,
  duration INT NOT NULL,              -- in Sekunden
  transfers INT DEFAULT 0,
  chosenAt DATETIME NOT NULL,
  createdAt DATETIME(3) NOT NULL,
  updatedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_journey_creator (creatorId),
  INDEX idx_pt_journey_trip (tripId),
  INDEX idx_pt_journey_departure (departureTime),
  CONSTRAINT fk_pt_journey_creator FOREIGN KEY (creatorId) REFERENCES User(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_journey_trip FOREIGN KEY (tripId) REFERENCES TravelTrip(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.3 `PtLeg`

Einzelner Fahrtabschnitt einer gespeicherten Verbindung.

```sql
CREATE TABLE PtLeg (
  id VARCHAR(191) NOT NULL,
  journeyId VARCHAR(191) NOT NULL,
  legIndex TINYINT NOT NULL,
  mode VARCHAR(50) NOT NULL,          -- WALK, BUS, RAIL, TRAM, SUBWAY, etc.
  lineName VARCHAR(191) NULL,         -- z.B. "ICE 123"
  lineProduct VARCHAR(50) NULL,       -- z.B. "nationalExpress", "regional"
  fromStationId VARCHAR(191) NOT NULL,
  fromStationName VARCHAR(255) NOT NULL,
  toStationId VARCHAR(191) NOT NULL,
  toStationName VARCHAR(255) NOT NULL,
  tripId VARCHAR(191) NULL,           -- Transitious Trip-ID (für Refresh)
  plannedDeparture DATETIME NOT NULL,
  plannedArrival DATETIME NOT NULL,
  actualDeparture DATETIME NULL,
  actualArrival DATETIME NULL,
  departureDelay INT NULL,            -- in Sekunden
  arrivalDelay INT NULL,              -- in Sekunden
  departurePlatform VARCHAR(10) NULL,
  arrivalPlatform VARCHAR(10) NULL,
  cancelled TINYINT(1) DEFAULT 0,
  realTimeState VARCHAR(20) NULL,     -- CANCELED, UPDATED, SCHEDULED
  rawResponse JSON NULL,
  lastCheckedAt DATETIME(3) NULL,
  createdAt DATETIME(3) NOT NULL,
  updatedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_leg_journey (journeyId),
  INDEX idx_pt_leg_trip (tripId),
  INDEX idx_pt_leg_lastChecked (lastCheckedAt),
  CONSTRAINT fk_pt_leg_journey FOREIGN KEY (journeyId) REFERENCES PtJourney(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.4 `PtParticipant`

Teilnehmer-Verknüpfung.

```sql
CREATE TABLE PtParticipant (
  journeyId VARCHAR(191) NOT NULL,
  userId VARCHAR(191) NOT NULL,
  addedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (journeyId, userId),
  CONSTRAINT fk_pt_participant_journey FOREIGN KEY (journeyId) REFERENCES PtJourney(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_participant_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 5. API-Endpunkte (öffentlich, JWT-geschützt)

### 5.1 Stationen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/stations` | Stationen suchen (Autocomplete) |
| `GET` | `/public-transport/stations/{id}/departures` | Abfahrtsplan einer Station |

#### `GET /public-transport/stations`

Query-Parameter:
- `q` (required): Suchbegriff
- `limit` (optional, default: 10): Max. Ergebnisse

#### `GET /public-transport/stations/{id}/departures`

Query-Parameter:
- `limit` (optional, default: 10): Anzahl Abfahrten
- `arriveBy` (optional, default: false): `true` für Ankünfte

### 5.2 Verbindungen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/public-transport/journeys` | Verbindung suchen |
| `POST` | `/public-transport/journeys` | Verbindung speichern |
| `GET` | `/public-transport/journeys/list` | Eigene Verbindungen auflisten |
| `GET` | `/public-transport/journeys/{id}` | Verbindungsdetails |
| `DELETE` | `/public-transport/journeys/{id}` | Verbindung löschen |
| `POST` | `/public-transport/journeys/{id}/refresh` | Verbindung aktualisieren |
| `POST` | `/public-transport/journeys/{id}/participants` | Teilnehmer hinzufügen |
| `DELETE` | `/public-transport/journeys/{id}/participants/{userId}` | Teilnehmer entfernen |

## 6. Technische Architektur

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
        ├── /api/v5/stoptimes (Abfahrtsplan)
        └── /api/v5/trip (Trip-Details)
```

## 7. Implementierungs-Reihenfolge

### Phase 1: Planung & Vorbereitung
- [x] Alte Implementation entfernen
- [x] Transitious API recherchieren
- [ ] plan.md erstellen (dieses Dokument)

### Phase 2: Datenbank & Service
- [ ] SQL-Migration: `PtStation`, `PtJourney`, `PtLeg`, `PtParticipant`
- [ ] `PtStationRepository` – CRUD für Station-Cache
- [ ] `PtJourneyRepository` – CRUD für Journey + Legs + Participants
- [ ] `PtService` – Kommunikation mit Transitious API inkl. Mapping

### Phase 3: Controller & Routes
- [ ] `PtController` – Alle API-Endpunkte
- [ ] Routen in `config/routes.php` registrieren
- [ ] Dependency Wiring in `config/dependencies.php`

### Phase 4: Cron-Tasks
- [ ] Optional: Stations-Cache-Refresh

### Phase 5: Dokumentation
- [ ] `docs/public-transport/readme.md` aktualisieren
- [ ] `openapi.yaml` aktualisieren
- [ ] `.htaccess` prüfen

## 8. Wichtige Hinweise

### User-Agent Header
Anfordern muss der Request immer einen `User-Agent` Header enthalten:
```
User-Agent: SinclearAPI/2.0.0 (https://sinclear.de)
```

### Zeitformat
- Transitious liefert ISO 8601: `2026-07-17T10:00:00Z`
- API speichert/liefert UTC: `2026-07-17 10:00:00`
- Konvertierung erfolgt in der Service-Schicht

### Station-IDs
Transitious verwendet interne Stop-IDs. Diese können von den bisherigen
DB-API-IDs abweichen. Ein Mapping ist ggf. erforderlich.

### Open-Source Pflicht
Die App muss unter einer Open-Source-Lizenz veröffentlicht werden.
Attribution: Link zu https://transitous.org/sources/ erforderlich.
