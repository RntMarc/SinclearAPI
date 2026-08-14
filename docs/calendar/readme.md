# Kalender (Calendar)

Die Kalender-Funktion erlaubt Nutzern das Verwalten von persönlichen
Kalender-Einträgen (Kalender-Events, nicht zu verwechseln mit Reise-Events
aus `TravelEvent`). Jeder Nutzer kann eigene Einträge erstellen, ändern und
löschen, andere Nutzer hinzufügen und die Sichtbarkeit festlegen.
Hinzugefügte Teilnehmer haben die gleichen Bearbeitungsrechte wie
der Ersteller (Event ändern, löschen, Teilnehmer verwalten).

Zusätzlich bietet `GET /calendar/all` einen kombinierten Feed, der neben den
Kalender-Events auch Reise-Events, Reisen, Geburtstage und ÖPNV-Fahrten
ausgibt (siehe [Kombinierter Kalender-Feed](#kombinierter-kalender-feed)).

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Das Format ist `YYYY-MM-DD HH:MM:SS` (24h, ohne Millisekunden, ohne Zeitzonenindikatoren). Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC vor dem Senden und von UTC in die lokale Zeitzone bei der Anzeige verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `CalendarEvent` | Kalender-Einträge (Titel, Beschreibung, Zeitraum, Sichtbarkeit) |
| `CalendarEventParticipant` | Verknüpfung von Nutzern mit Kalender-Einträgen |

## Sichtbarkeit (Visibility)

Jeder Kalender-Eintrag hat ein `visibility`-Feld (0–2):

| Wert | Bedeutung | Sichtbar für |
|------|-----------|-------------|
| `0` | Privat | Nur Ersteller und explizit hinzugefügte Teilnehmer (`CalendarEventParticipant`) |
| `1` | Öffentlich | Alle Nutzer |
| `2` | Enge Freunde | Nutzer, die in der `CloseFriend`-Liste des Erstellers stehen |

## Autorisierungs-Logik

Alle Endpunkte benötigen einen gültigen JWT (Bearer Token).

| Endpunkt | Zugriffsprüfung |
|----------|----------------|
| `POST /calendar` | Authentifizierter Nutzer |
| `GET /calendar` | Events, die der Nutzer sehen darf (siehe Sichtbarkeit) |
| `GET /calendar/all` | Kombinierter Feed, quellenabhängig gefiltert (siehe unten) |
| `GET /calendar/{id}` | Nutzer muss das Event sehen dürfen → sonst `404` |
| `PUT /calendar/{id}` | Ersteller und alle Teilnehmer → sonst `403` |
| `DELETE /calendar/{id}` | Ersteller und alle Teilnehmer → sonst `403` |
| `POST /calendar/{id}/participants` | Ersteller und alle Teilnehmer |
| `DELETE /calendar/{id}/participants/{userId}` | Ersteller und alle Teilnehmer |

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/calendar` | JWT | Paginierte Liste der sichtbaren Kalender-Events (mit Zeitfilter) |
| `GET` | `/calendar/all` | JWT | Kombinierter Feed (Events, Reisen, Reise-Events, Geburtstage, ÖPNV) |
| `POST` | `/calendar` | JWT | Neues Kalender-Event erstellen |
| `GET` | `/calendar/{id}` | JWT | Kalender-Event-Details (mit Teilnehmern) |
| `PUT` | `/calendar/{id}` | JWT | Kalender-Event ändern |
| `DELETE` | `/calendar/{id}` | JWT | Kalender-Event löschen |
| `POST` | `/calendar/{id}/participants` | JWT | Teilnehmer zu einem Event hinzufügen |
| `DELETE` | `/calendar/{id}/participants/{userId}` | JWT | Teilnehmer aus einem Event entfernen |

### `GET /calendar` – Query-Parameter

| Parameter | Typ | Beschreibung |
|-----------|-----|-------------|
| `page` | int (default 1) | Seitenzahl |
| `limit` | int (default 20, max 100) | Einträge pro Seite |
| `start` | `YYYY-MM-DD HH:MM:SS` | Manueller Start der Zeitspanne in UTC (z. B. `2026-06-01 00:00:00`) |
| `end` | `YYYY-MM-DD HH:MM:SS` | Manuelles Ende der Zeitspanne in UTC |
| `range` | `week` oder `month` | Vordefinierter Bereich (aktuelle Woche / aktueller Monat). Wird ignoriert wenn `start` + `end` gesetzt sind |

**Beispiele:**
```
GET /calendar?page=1&limit=20
GET /calendar?start=2026-06-01 00:00:00&end=2026-06-30 23:59:59
GET /calendar?range=week
GET /calendar?range=month&page=1&limit=50
```

### Response-Format

Ein Kalender-Event wird immer mit Teilnehmern ausgeliefert:

```json
{
  "data": {
    "id": "01912345-6789-....",
    "creatorId": "uuid-des-erstellers",
    "title": "Team Meeting",
    "description": "Wöchentliches Sync",
    "startTime": "2026-07-01 10:00:00",
    "endTime": "2026-07-01 11:00:00",
    "visibility": 1,
    "participants": [
      { "id": "uuid", "displayName": "Max", "image": null }
    ],
    "createdAt": "2026-06-26 10:00:00",
    "updatedAt": "2026-06-26 10:00:00"
  }
}
```

### `POST /calendar` – Request

```json
{
  "title": "Team Meeting",
  "description": "Wöchentliches Sync",
  "startTime": "2026-07-01 10:00:00",
  "endTime": "2026-07-01 11:00:00",
  "visibility": 1,
  "participants": ["user-uuid-1", "user-uuid-2"]
}
```

`participants` ist optional.

### `PUT /calendar/{id}` – Request (partielles Update)

Nur die zu ändernden Felder mitsenden:

```json
{
  "title": "Geändertes Meeting",
  "startTime": "2026-07-01 14:00:00"
}
```

### `POST /calendar/{id}/participants` – Request

```json
{
  "userId": "uuid-des-hinzuzufügenden-nutzers"
}
```

## CalDAV (read-only)

Die API stellt eine lesende CalDAV-Schnittstelle unter `/api/dav/` bereit,
über die Kalender-Apps (DAVx5, Apple Kalender, Thunderbird) die sichtbaren
`CalendarEvent`-Einträge des Nutzers synchronisieren können. Die
Authentifizierung erfolgt per Basic-Auth mit E-Mail-Adresse + DAV-Token
(Verwaltung über `POST/GET/DELETE /user/me/dav-tokens`).

Details (Einrichtung, ICS-Abbildung, Verhalten bei ungültigem Token) siehe
`docs/caldav-carddav.md`.

## Kombinierter Kalender-Feed`GET /calendar/all` aggregiert alle für den Nutzer sichtbaren Termine aus
fünf Quellen zu einer flachen, nach `startTime` aufsteigend sortierten Liste.
Der Endpunkt ist ohne Pagination – stattdessen wird über einen Zeitbereich
gefiltert; pro Quelle werden maximal 500 Einträge zurückgegeben.

### Query-Parameter

| Parameter | Typ | Beschreibung |
|-----------|-----|-------------|
| `start` | `YYYY-MM-DD HH:MM:SS` | Beginn des Zeitraums in UTC. Muss zusammen mit `end` gesetzt werden. |
| `end` | `YYYY-MM-DD HH:MM:SS` | Ende des Zeitraums in UTC. Muss zusammen mit `start` gesetzt werden. |
| `types` | string | Komma-separierte Liste der gewünschten Typen (`calendar_event`, `travel_event`, `trip`, `birthday`, `pt_journey`). Standard: alle Typen. |

Ohne `start`/`end` wird der aktuelle Monat verwendet. Wird nur einer der
beiden Parameter gesetzt, antwortet die API mit `400 invalid_time_range`;
bei ungültigem Format mit `400 invalid_datetime` und bei unbekanntem Typ
mit `400 invalid_type`.

**Beispiele:**
```
GET /calendar/all
GET /calendar/all?start=2026-06-01 00:00:00&end=2026-06-30 23:59:59
GET /calendar/all?types=calendar_event,trip
GET /calendar/all?types=birthday&start=2026-01-01 00:00:00&end=2026-12-31 23:59:59
```

### Enthaltene Typen und Zugriffsregeln

| type | Quelle | Zugriff / Sichtbarkeit |
|------|--------|------------------------|
| `calendar_event` | `CalendarEvent` | Wie `GET /calendar` (Ersteller, Teilnehmer, Visibility 0/1/2) |
| `travel_event` | `TravelEvent` | Standalone-Events über `EventRelation`, Reise-Events über die `TravelRelation` der Reise |
| `trip` | `TravelTrip` | Reisen, bei denen der Nutzer in `TravelRelation` steht (als mehrtägiger Zeitraum, `allDay`) |
| `birthday` | `User.birthday` | Eigenes Geburtsdatum immer; sonst über `birthdayVisibility` (1 = alle, 2 = enge Freunde, 0 = niemand). Wiederkehrend je Jahr im Zeitraum (`allDay`) |
| `pt_journey` | `PtJourney` | Fahrten, bei denen der Nutzer in `PtParticipant` steht (Creator ist immer Teilnehmer) |

### Response-Format

```json
{
  "data": [
    {
      "type": "calendar_event",
      "id": "uuid-des-events",
      "title": "Team Meeting",
      "startTime": "2026-07-01 10:00:00",
      "endTime": "2026-07-01 11:00:00",
      "allDay": false,
      "detail": { }
    },
    {
      "type": "trip",
      "id": "uuid-der-reise",
      "title": "Berlin Trip",
      "startTime": "2026-07-03 00:00:00",
      "endTime": "2026-07-06 23:59:59",
      "allDay": true,
      "detail": { }
    },
    {
      "type": "birthday",
      "id": "2026-05-12-uuid-des-nutzers",
      "title": "Geburtstag: Max",
      "startTime": "2026-05-12 00:00:00",
      "endTime": "2026-05-12 23:59:59",
      "allDay": true,
      "detail": {
        "userId": "uuid-des-nutzers",
        "displayName": "Max",
        "image": null,
        "birthday": "1990-05-12",
        "occurrenceDate": "2026-05-12"
      }
    }
  ],
  "meta": {
    "start": "2026-06-01 00:00:00",
    "end": "2026-06-30 23:59:59",
    "types": ["calendar_event", "travel_event", "trip", "birthday", "pt_journey"],
    "count": 3,
    "truncated": false
  }
}
```

**Gemeinsame Item-Felder:**

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `type` | string | `calendar_event`, `travel_event`, `trip`, `birthday` oder `pt_journey` |
| `id` | string | ID des zugrunde liegenden Datensatzes (bei Geburtstagen: `Vorkommensdatum + Nutzer-ID`) |
| `title` | string\|null | Anzeige-Titel (bei Geburtstagen servergeneriert: `Geburtstag: <displayName>`) |
| `startTime` | string\|null | Beginn in UTC |
| `endTime` | string\|null | Ende in UTC |
| `allDay` | bool | `true` bei Reisen und Geburtstagen |
| `detail` | object | Typspezifisches Objekt: `calendar_event` → CalendarEvent, `travel_event` → TravelEvent inkl. `participants`, `trip` → Trip-Datensatz, `birthday` → Nutzer-Kurzinfo inkl. `occurrenceDate`, `pt_journey` → Fahrten-Zusammenfassung inkl. `legs` |

**Geburtstags-Logik:** Gespeichert ist das Geburtsdatum (`YYYY-MM-DD`). Für
jedes Jahr im angefragten Zeitraum wird ein Vorkommen erzeugt, sofern der
Monat/Tag existiert (29. Februar nur in Schaltjahren).

## Moderation

Kalender-Events können über das Melde- und Anfragensystem gemeldet werden.
Der Eigentümer ist der Ersteller des Events (`creatorId`).

| objectType | Beschreibung | Eigentümer |
|------------|-------------|------------|
| `calendar_event` | Kalender-Event | `creatorId` des Events |

Details siehe `docs/moderation-requests/readme.md`.

## Datenbank-Schema (Referenz)

```sql
CREATE TABLE IF NOT EXISTS `CalendarEvent` (
  `id`         varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `creatorId`  varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`      varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `startTime`  datetime NOT NULL,
  `endTime`    datetime NOT NULL,
  `visibility` tinyint(1) NOT NULL DEFAULT 0,
  `createdAt`  datetime(3) NOT NULL,
  `updatedAt`  datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_calendar_creator` (`creatorId`),
  KEY `idx_calendar_time` (`startTime`, `endTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CalendarEventParticipant` (
  `eventId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId`  varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `addedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`eventId`, `userId`),
  KEY `idx_calendar_participant_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
