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
  "frequency_seconds": 600
}
```

**Validierung:**
- `recipient_ids`: Array, 1–50 Einträge, gültige UUIDs
- `duration_seconds`: 300–86400 (5 Min – 24 h)
- `frequency_seconds`: 300–1200 (5–20 Min), Default 600

**Response (201):**
```json
{
  "data": {
    "id": "uuid",
    "ownerId": "uuid",
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
    "lastLocation": null
  }
}
```

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
