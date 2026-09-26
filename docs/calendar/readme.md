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

> **Hinweis zu Zeitangaben:** Die API ist zeitzonen-bewusst. Jeder Eintrag trägt eine IANA-Zeitzone (`timezone`, z. B. `Europe/Berlin`).
> 
> - **Getaktet (`allDay: false`):** `startAt`/`endAt` sind RFC 3339 **mit Offset** (`2026-09-26T14:30:00+02:00` oder `...Z`); intern wird der UTC-Instant gespeichert.
> - **Ganztägig (`allDay: true`):** `startDate`/`endDate` sind zivile Tage (`YYYY-MM-DD`, inklusives Ende), ohne Uhrzeitfelder.
> - **Ausgabe:** Getaktete Einträge werden im Offset ihrer `timezone` ausgegeben, ganztägige als `startDate`/`endDate`. `createdAt`/`updatedAt` sind RFC 3339 in UTC (`Z`).
> - Clients senden Wandzeiten mit Offset und der gemeinten IANA-Zeitzone; die eigene Zeitzone stammt aus `UserPreferences.timezone` bzw. der Gerätezeitzone.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `CalendarEvent` | Kalender-Einträge (Titel, Beschreibung, `allDay`, `timezone`, `startAt`/`endAt` bzw. `startDate`/`endDate`, Sichtbarkeit) |
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
| `start` | `YYYY-MM-DD` | Manueller Start der Zeitspanne (ziviler Tag in `timezone`). Events deren Ende nach oder an diesem Tag liegen werden zurückgegeben. |
| `end` | `YYYY-MM-DD` | Manuelles Ende der Zeitspanne (inklusiv). Events deren Start vor oder an diesem Tag liegen werden zurückgegeben. |
| `timezone` | IANA (default `UTC`) | Zeitzone, in der die Tagesgrenzen von `start`/`end` liegen (z. B. `Europe/Berlin`). |
| `range` | `week` oder `month` | Vordefinierter Bereich (aktuelle Woche / aktueller Monat in `timezone`). Wird ignoriert wenn `start` + `end` gesetzt sind |

**Beispiele:**
```
GET /calendar?page=1&limit=20
GET /calendar?start=2026-06-01&end=2026-06-30&timezone=Europe/Berlin
GET /calendar?range=week&timezone=Europe/Berlin
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
    "allDay": false,
    "timezone": "Europe/Berlin",
    "startAt": "2026-07-01T10:00:00+02:00",
    "endAt": "2026-07-01T11:00:00+02:00",
    "visibility": 1,
    "participants": [
      { "id": "uuid", "displayName": "Max", "image": null }
    ],
    "createdAt": "2026-06-26T10:00:00Z",
    "updatedAt": "2026-06-26T10:00:00Z"
  }
}
```

Ganztägige Einträge liefern stattdessen `startDate`/`endDate` und kein
`startAt`/`endAt`:

```json
{
  "allDay": true,
  "timezone": "Europe/Berlin",
  "startDate": "2026-07-01",
  "endDate": "2026-07-03"
}
```

### `POST /calendar` – Request

```json
{
  "title": "Team Meeting",
  "description": "Wöchentliches Sync",
  "allDay": false,
  "timezone": "Europe/Berlin",
  "startAt": "2026-07-01T10:00:00+02:00",
  "endAt": "2026-07-01T11:00:00+02:00",
  "visibility": 1,
  "participants": ["user-uuid-1", "user-uuid-2"]
}
```

`participants` ist optional. Bei ganztägigen Events (`allDay: true`) werden
statt `startAt`/`endAt` die Felder `startDate`/`endDate` (`YYYY-MM-DD`)
gesendet; Uhrzeitfelder sind dann unzulässig.

### `PUT /calendar/{id}` – Request (partielles Update)

Nur die zu ändernden Felder mitsenden:

```json
{
  "title": "Geändertes Meeting",
  "timezone": "Europe/Berlin",
  "startAt": "2026-07-01T14:00:00+02:00",
  "endAt": "2026-07-01T15:00:00+02:00"
}
```

Beim Umschalten eines bestehenden Events auf ganztägig (`allDay: true`)
müssen `startDate`/`endDate` gesendet werden; die API leert die Instant-Felder
automatisch (und umgekehrt).

### `POST /calendar/{id}/participants` – Request

```json
{
  "userId": "uuid-des-hinzuzufügenden-nutzers"
}
```

## Fehlercodes

Fehlerantworten folgen dem `Error`-Schema `{"error": "<code>"}`. Das
zusätzliche Feld `message` mit der konkreten Ursache liefert die API nur,
wenn sie im Debug-Modus läuft; Clients sollen am Code `error` orientieren.
Jeder abgefangene Fehler wird im Anwendungslog (`var/log/app.log`)
geschrieben und ab Stufe `error` zusätzlich in PHPs `error_log` des
Hostings.

| Code | Status | Bedeutung |
|------|--------|-----------|
| `title_required` | 400 | `title` fehlt oder ist leer. |
| `invalid_visibility` | 400 | `visibility` ausserhalb 0–2. |
| `date_required` | 400 | Ganztägiges Event ohne `startDate`/`endDate`. |
| `time_required` | 400 | Getaktetes Event ohne `startAt`/`endAt`. |
| `time_forbidden` | 400 | `allDay: true` zusammen mit `startAt`/`endAt`. |
| `date_forbidden` | 400 | Getaktetes Event mit `startDate`/`endDate`. |
| `invalid_date` | 400 | Datum nicht im Format `YYYY-MM-DD`. |
| `invalid_datetime` | 400 | `startAt`/`endAt` nicht RFC 3339 mit Offset. |
| `invalid_timezone` | 400 | Unbekannte IANA-Zeitzone. |
| `invalid_time_range` | 400 | Ende liegt nicht nach Beginn; beim Feed unvollständiger Zeitraum. |
| `invalid_type` | 400 | Unbekannter Wert in `types` des Feeds. |
| `invalid_value` | 400 | Wert passt nicht ins Spaltenformat (z. B. ungültige Zeitangabe). |
| `invalid_reference` | 400 | Referenz existiert nicht (Fremdschlüsselverletzung). |
| `userId_required` | 400 | `POST .../participants` ohne `userId`. |
| `no_fields_to_update` | 400 | `PUT /calendar/{id}` ohne änderbare Felder. |
| `unauthorized` | 401 | Kein gültiges Token. |
| `forbidden` | 403 | Keine Berechtigung (weder Ersteller noch Teilnehmer). |
| `event_not_found` | 404 | Event existiert nicht oder ist für den Nutzer nicht sichtbar. |
| `conflict` | 409 | Datensatz existiert bereits (Duplikat). |
| `internal_error` | 500 | Unerwarteter Fehler; Details stehen im Serverlog. |

## CalDAV (read-only)

Die API stellt eine lesende CalDAV-Schnittstelle unter `/api/dav/` bereit,
über die Kalender-Apps (DAVx5, Apple Kalender, Thunderbird) die sichtbaren
`CalendarEvent`-Einträge des Nutzers synchronisieren können. Die
Authentifizierung erfolgt per Basic-Auth mit E-Mail-Adresse + DAV-Token
(Verwaltung über `POST/GET/DELETE /user/me/dav-tokens`).

Details (Einrichtung, ICS-Abbildung, Verhalten bei ungültigem Token) siehe
`docs/caldav-carddav.md`.

## Kombinierter Kalender-Feed`GET /calendar/all` aggregiert alle für den Nutzer sichtbaren Termine aus
fünf Quellen zu einer flachen, chronologisch aufsteigend sortierten Liste.
Der Endpunkt ist ohne Pagination – stattdessen wird über einen Zeitbereich
gefiltert; pro Quelle werden maximal 500 Einträge zurückgegeben.

### Query-Parameter

| Parameter | Typ | Beschreibung |
|-----------|-----|-------------|
| `start` | `YYYY-MM-DD` | Beginn des Zeitraums (ziviler Tag in `timezone`). Muss zusammen mit `end` gesetzt werden. |
| `end` | `YYYY-MM-DD` | Ende des Zeitraums (inklusiv). Muss zusammen mit `start` gesetzt werden. |
| `timezone` | IANA (default `UTC`) | Zeitzone, in der die Tagesgrenzen von `start`/`end` liegen. |
| `types` | string | Komma-separierte Liste der gewünschten Typen (`calendar_event`, `travel_event`, `trip`, `birthday`, `pt_journey`). Standard: alle Typen. |

Ohne `start`/`end` wird der aktuelle Monat verwendet. Wird nur einer der
beiden Parameter gesetzt, antwortet die API mit `400 invalid_time_range`;
bei ungültigem Format mit `400 invalid_datetime` und bei unbekanntem Typ
mit `400 invalid_type`.

**Beispiele:**
```
GET /calendar/all
GET /calendar/all?start=2026-06-01&end=2026-06-30
GET /calendar/all?types=calendar_event,trip
GET /calendar/all?types=birthday&start=2026-01-01&end=2026-12-31
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
      "timezone": "Europe/Berlin",
      "startAt": "2026-07-01T10:00:00+02:00",
      "endAt": "2026-07-01T11:00:00+02:00",
      "allDay": false,
      "detail": { }
    },
    {
      "type": "trip",
      "id": "uuid-der-reise",
      "title": "Berlin Trip",
      "timezone": "Europe/Berlin",
      "startDate": "2026-07-03",
      "endDate": "2026-07-06",
      "allDay": true,
      "detail": { }
    },
    {
      "type": "birthday",
      "id": "2026-05-12-uuid-des-nutzers",
      "title": "Geburtstag: Max",
      "timezone": "UTC",
      "startDate": "2026-05-12",
      "endDate": "2026-05-12",
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
    "start": "2026-06-01",
    "end": "2026-06-30",
    "timezone": "Europe/Berlin",
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
| `allDay` | bool | `true` bei ganztägigen Einträgen (Reisen, Geburtstage, optional bei Kalender-Events) |
| `timezone` | string | IANA-Zeitzone des Eintrags (`UTC` bei Geburtstagen) |
| `startAt` | string\|null | Start als RFC 3339 mit Offset (nur bei `!allDay`) |
| `endAt` | string\|null | Ende als RFC 3339 mit Offset (nur bei `!allDay`) |
| `startDate` | string\|null | Start-Datum, Format `YYYY-MM-DD` (nur bei `allDay`) |
| `endDate` | string\|null | End-Datum, Format `YYYY-MM-DD`, inklusiv (nur bei `allDay`) |
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
  `allDay`     tinyint(1) NOT NULL DEFAULT 0,
  `timezone`   varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Berlin',
  `startAt`    datetime(3) DEFAULT NULL,
  `endAt`      datetime(3) DEFAULT NULL,
  `startDate`  date DEFAULT NULL,
  `endDate`    date DEFAULT NULL,
  `visibility` tinyint(1) NOT NULL DEFAULT 0,
  `createdAt`  datetime(3) NOT NULL,
  `updatedAt`  datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_calendar_creator` (`creatorId`),
  KEY `idx_calendar_instant` (`startAt`, `endAt`),
  KEY `idx_calendar_day` (`startDate`, `endDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CalendarEventParticipant` (
  `eventId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId`  varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `addedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`eventId`, `userId`),
  KEY `idx_calendar_participant_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
