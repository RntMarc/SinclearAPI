# Location Sharing API

## Übersicht

Die Location-Sharing-API ermöglicht es Nutzern, ihren Live-Standort mit ausgewählten Kontakten zu teilen, vergleichbar mit Funktionen aus WhatsApp oder Google Maps.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/location-sharing/sessions` | Neue Session erstellen |
| `GET` | `/location-sharing/sessions` | Eigene aktive Sessions |
| `GET` | `/location-sharing/sessions/:id` | Session-Details + letzte Locations |
| `PATCH` | `/location-sharing/sessions/:id` | Dauer verlängern / Session beenden |
| `DELETE` | `/location-sharing/sessions/:id` | Session beenden |
| `POST` | `/location-sharing/sessions/:id/locations` | Standort an Session senden |
| `GET` | `/location-sharing/sessions/:id/locations` | Location-Verlauf (Polling) |
| `GET` | `/location-sharing/active` | Aktive Sessions von Kontakten |

## Session erstellen

```
POST /api/v2/location-sharing/sessions
```

**Request:**
```json
{
  "recipient_ids": ["uuid1", "uuid2"],
  "duration_seconds": 3600,
  "frequency_seconds": 600,
  "sharing_mode": "route"
}
```

**Validierung:**
- `recipient_ids`: Array, 1–50 Einträge, gültige UUIDs
- `duration_seconds`: 300–86400 (5 Min – 24 h)
- `frequency_seconds`: 300–1200 (5–20 Min), Default 600
- `sharing_mode`: `location` (Default) oder `route`

**Response (201):**
```json
{
  "data": {
    "id": "uuid",
    "ownerId": "uuid",
    "sharingMode": "route",
    "durationSeconds": 3600,
    "frequencySeconds": 600,
    "isActive": true,
    "startedAt": "2026-07-03 12:00:00",
    "expiresAt": "2026-07-03 13:00:00",
    "createdAt": "2026-07-03 12:00:00",
    "updatedAt": "2026-07-03 12:00:00",
    "recipients": [
      {"userId": "uuid1", "displayName": "User 1", "image": null}
    ],
    "lastLocation": null,
    "locationCount": 0
  }
}
```

## Sharing-Modi

Die API unterstützt zwei Sharing-Modi:

| Modus | Beschreibung | Use-Case |
|-------|-------------|----------|
| `location` | Nur aktueller Standort wird geteilt | "Wo bin ich gerade?" |
| `route` | Standort + Strecke (alle Standpunkte) | "Welchen Weg habe ich zurückgelegt?" |

- **`location`** (Default): Speichert alle Standortpunkte, aber die Clients zeigen typischerweise nur den aktuellen Standort an. Gut für Live-Standort-Teilen.
- **`route`**: Speichert alle Standortpunkte und Clients können den vollen Pfad abrufen. Gut für Aktivitäten wie Wandern, Laufen oder Fahrradfahren.

**Hinweis:** Der `sharingMode` kann nach der Session-Erstellung nicht geändert werden. Alle Standortpunkte werden in beiden Modi gespeichert – der Modus bestimmt nur, wie die Daten von den Clients angezeigt werden.

## Standort senden

```
POST /api/v2/location-sharing/sessions/:id/locations
```

**Request:**
```json
{
  "latitude": 48.137154,
  "longitude": 11.576124,
  "accuracy": 10.5,
  "recordedAt": "2026-07-03 12:05:00"
}
```

**Validierung:**
- `latitude`: -90 bis 90
- `longitude`: -180 bis 180
- `accuracy`: Optional, >= 0
- `recordedAt`: UTC-Zeitstempel im Format `YYYY-MM-DD HH:MM:SS`, nicht in der Zukunft, max. 5 Minuten alt

## Session verlängern / beenden

```
PATCH /api/v2/location-sharing/sessions/:id
```

**Request:**
```json
{
  "duration_seconds": 7200,
  "is_active": false
}
```

Beide Felder sind optional. `is_active: false` beendet die Session manuell.

## Location-Verlauf (Polling)

```
GET /api/v2/location-sharing/sessions/:id/locations?since=2026-07-03 12:00:00
```

Der `since`-Parameter ist optional. Ohne ihn werden alle Standorte zurückgegeben. Der Wert muss ein UTC-Zeitstempel im Format `YYYY-MM-DD HH:MM:SS` sein.

## Aktive Sessions von Kontakten

```
GET /api/v2/location-sharing/active
```

Gibt alle aktiven Sessions zurück, bei denen der aktuelle Nutzer als Empfänger eingetragen ist. Enthält Besitzer-Informationen und die letzte bekannte Position.

## Zugriffskontrolle

| Aktion | Owner | Empfänger | Admin |
|--------|-------|-----------|-------|
| Session erstellen | Ja | — | — |
| Eigene Sessions lesen | Ja | — | — |
| Session-Details lesen | Ja | Ja | Ja |
| Session aktualisieren | Ja | — | Ja |
| Session beenden | Ja | — | Ja |
| Standort senden | Ja | — | Ja |
| Location-Verlauf lesen | Ja | Ja | Ja |
| Aktive Sessions von Kontakten | — | Ja | — |

## Client-Implementierung

### Hintergrund-Updates
- **Android:** Wake Lock oder periodische Wecker (AlarmManager) für regelmäßige Standort-Updates
- **Web:** Service Worker mit Periodic Sync oder Hintergrund-Fetch API
- **Desktop:** Kein Standort-Sharing, nur Anzeige geteilter Standorte

### Empfohlene Frequenz
- Default: 10 Minuten (600 Sekunden)
- Minimum: 5 Minuten (300 Sekunden) – schnellste Aktualisierung
- Maximum: 20 Minuten (1200 Sekunden) – langsamste Aktualisierung

### Polling-Strategie
Clients sollten den `since`-Parameter verwenden, um nur neue Standorte abzurufen:
1. Beim ersten Abruf: `GET /sessions/:id/locations` (alle Standorte)
2. Bei Folge-Abrufen: `GET /sessions/:id/locations?since=<letzter_timestamp>`

## Zeitformat

Die API arbeitet ausschließlich in UTC. Alle Datumswerte (Input und Output) verwenden das Format `YYYY-MM-DD HH:MM:SS`. Clients müssen lokale Zeiten vor dem Senden nach UTC umrechnen und empfangene UTC-Zeiten für die Anzeige in die lokale Zeitzone des Nutzers konvertieren.

## Drittanbieter-Integration (Third-Party Clients)

Statt der direkten Nutzung der Flutter-App können Nutzer etablierte Tracking-Apps wie OsmAnd, GpsLogger, Owntracks oder Traccar verwenden. Die API stellt für jede dieser Apps einen dedizierten, unauthentifizierten Endpunkt bereit.

### Wie es funktioniert

1. Nutzer erstellt eine Location-Sharing-Session über die API
2. Die Response enthält `token` und `integrationUrls` mit passenden URL-Vorlagen
3. Nutzer installiert eine beliebige Tracking-App und trägt die URL ein
4. Die App sendet Koordinaten automatisch an die API – ohne erneute Authentifizierung

### Unterstützte Apps

| App | URL-Muster | Methode | Parameter |
|-----|-----------|---------|-----------|
| **OsmAnd** | `/log/osmand/{token}[/{name}]?lat={0}&lon={1}&acc={3}&timestamp={2}` | GET | `{0}=lat, {1}=lon, {2}=timestamp, {3}=accuracy, {4}=altitude, {5}=speed, {6}=bearing` |
| **GpsLogger** | `/log/gpslogger/{token}[/{name}]?lat=%LAT&lon=%LON&acc=%ACC&timestamp=%TIMESTAMP` | GET | `%LAT, %LON, %ALT, %ACC, %SPD, %DIR, %TIMESTAMP, %BAT, %SAT` |
| **Owntracks** | `/log/owntracks/{token}[/{name}]` | POST | JSON: `{"lat":..., "lon":..., "acc":..., "ts":...}` |
| **Ulogger** | `/log/ulogger/{token}[/{name}]?lat=...&lon=...&time=...` | GET | `lat, lon, time` |
| **Traccar** | `/log/traccar/{token}[/{name}]?lat=...&lon=...&accuracy=...&timestamp=...` | GET | `lat, lon, accuracy, timestamp, altitude, speed, bearing` |
| **OpenGTS** | `/log/opengts/{token}[/{name}]?lat=...&lon=...&gpsAccuracy=...&time=...` | GET | `lat, lon, gpsAccuracy, time, speed, bearing` |
| **Overland** | `/log/overland/{token}[/{name}]` | POST | GeoJSON: `{"geometry":{"coordinates":[lon,lat]},"properties":{"timestamp":...,"horizontal_accuracy":...}}` |
| **Locus Maps** | `/log/locusmap/{token}[/{name}]?lat=...&lon=...&acc=...&time=...` | GET | `lat, lon, acc, time, alt, speed, bearing` |
| **HTTP GET** | `/log/get/{token}[/{name}]?lat=...&lon=...&acc=...&timestamp=...` | GET | `lat, lon, alt, acc, timestamp, speed, bearing, bat, sat` |
| **HTTP POST** | `/log/post/{token}[/{name}]` | POST | JSON: `{"lat":..., "lon":..., "acc":..., "timestamp":...}` |

### Session-Token

Jede Session erhält ein einzigartiges 32-Zeichen-hex Token (128 Bit). Dieses Token dient als Authentifizierung für die Drittanbieter-Endpunkte. Es ist NICHT das Auth-Token des Nutzers.

**Beispiel-Token:** `a1b2c3d4e5f678901234567890abcdef`

### Name-Parameter (optional)

Der `{name}`-Pfadparameter ist optional und kann weggelassen werden. Er ist ein beliebiger alphanumerischer Name (a-zA-Z0-9_-), den der Nutzer in der Tracking-App eingibt. Er dient nur zur Anzeige und wird nicht ausgewertet.

**Beispiele:**
- Mit Name: `https://api.sinclear.de/api/v2/location-sharing/log/owntracks/TOKEN/mein-handy`
- Ohne Name: `https://api.sinclear.de/api/v2/location-sharing/log/owntracks/TOKEN`

**Hinweis:** Viele Clients (z.B. Owntracks, Traccar) unterstützen standardmäßig keinen Namen in der URL. Die API akzeptiert beide Varianten.

### Zeitstempel

Die meisten Drittanbieter senden Unix-Epoch-Timestamps (Sekunden oder Millisekunden). Die API erkennt automatisch das Format und konvertiert es nach `YYYY-MM-DD HH:MM:SS`. Wenn kein Timestamp gesendet wird, verwendet die API die aktuelle Serverzeit.

### Beispiel: Session erstellen

**Request:**
```json
POST /api/v2/location-sharing/sessions
{
  "recipient_ids": ["uuid1"],
  "duration_seconds": 3600,
  "sharing_mode": "route"
}
```

**Response (201):**
```json
{
  "data": {
    "id": "uuid",
    "token": "a1b2c3d4e5f678901234567890abcdef",
    "ownerId": "uuid",
    "sharingMode": "route",
    "durationSeconds": 3600,
    "frequencySeconds": 600,
    "isActive": true,
    "startedAt": "2026-07-03 12:00:00",
    "expiresAt": "2026-07-03 13:00:00",
    "createdAt": "2026-07-03 12:00:00",
    "updatedAt": "2026-07-03 12:00:00",
    "recipients": [
      {"userId": "uuid1", "displayName": "User 1", "image": null}
    ],
    "lastLocation": null,
    "locationCount": 0,
    "integrationUrls": {
      "osmand": "https://api.sinclear.de/api/v2/location-sharing/log/osmand/a1b2c3d4e5f678901234567890abcdef/yourname?lat={0}&lon={1}&alt={4}&acc={3}&timestamp={2}&speed={5}&bearing={6}",
      "gpslogger": "https://api.sinclear.de/api/v2/location-sharing/log/gpslogger/a1b2c3d4e5f678901234567890abcdef/yourname?lat=%LAT&lon=%LON&sat=%SAT&alt=%ALT&acc=%ACC&speed=%SPD&bearing=%DIR&timestamp=%TIMESTAMP&bat=%BATT",
      "owntracks": "https://api.sinclear.de/api/v2/location-sharing/log/owntracks/a1b2c3d4e5f678901234567890abcdef/yourname",
      "ulogger": "https://api.sinclear.de/api/v2/location-sharing/log/ulogger/a1b2c3d4e5f678901234567890abcdef/yourname",
      "traccar": "https://api.sinclear.de/api/v2/location-sharing/log/traccar/a1b2c3d4e5f678901234567890abcdef/yourname",
      "opengts": "https://api.sinclear.de/api/v2/location-sharing/log/opengts/a1b2c3d4e5f678901234567890abcdef/yourname",
      "overland": "https://api.sinclear.de/api/v2/location-sharing/log/overland/a1b2c3d4e5f678901234567890abcdef/yourname",
      "locusmap": "https://api.sinclear.de/api/v2/location-sharing/log/locusmap/a1b2c3d4e5f678901234567890abcdef/yourname?lat=LAT&lon=LON&time=TIME&alt=ALT&speed=SPEED&bearing=BEARING",
      "httpGet": "https://api.sinclear.de/api/v2/location-sharing/log/get/a1b2c3d4e5f678901234567890abcdef/yourname?lat=LAT&lon=LON&alt=ALT&acc=ACC&bat=BAT&sat=SAT&speed=SPD&bearing=DIR&timestamp=TIME"
    }
  }
}
```

### Beispiel: OsmAnd konfigurieren

1. OsmAnd öffnen → Einstellungen → Plugins → "Aufzeichnung von Tracks" aktivieren
2. Menü → "Live-Teilen" → Server-URL eingeben:
   ```
   https://api.sinclear.de/api/v2/location-sharing/log/osmand/a1b2c3d4e5f678901234567890abcdef/yourname
   ```
3. Koordinaten-Format: `lat={0}&lon={1}&alt={4}&acc={3}&timestamp={2}`
4. Automatische Übermittlung aktivieren

### Sicherheit

- Der Endpunkt erfordert keine Authentifizierung – nur das Session-Token
- Das Token kann nicht zur Auslesen von Daten verwendet werden (nur Schreibzugriff)
- Sessions laufen automatisch nach `durationSeconds` ab
- Bei abgelaufenen Sessions wird `404 session_expired` zurückgegeben

## Automatisches Aufräumen

Die API bereinigt automatisch alte Location-Sharing-Daten:

- **MySQL Event:** Täglich werden Sessions gelöscht, die älter als 7 Tage sind
- **Cascade:** Zugehörige Standortpunkte und Empfänger werden ebenfalls gelöscht
- **Keine manuelle Bereinigung nötig:** Das System verwaltet die Datenhaltung automatisch
