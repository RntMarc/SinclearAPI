# Kalender (Calendar)

Die Kalender-Funktion erlaubt Nutzern das Verwalten von persönlichen
Kalender-Einträgen (Kalender-Events, nicht zu verwechseln mit Reise-Events
aus `TravelEvent`). Jeder Nutzer kann eigene Einträge erstellen, ändern und
löschen, andere Nutzer hinzufügen und die Sichtbarkeit festlegen.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Die API akzeptiert ISO-8601-Strings **mit oder ohne Zeitzonenangabe** (z. B. `2026-07-01T12:00:00+02:00` oder `2026-07-01T10:00:00Z`) und konvertiert diese zuverlässig nach UTC. Clients können lokale Zeitangaben also direkt mitsenden – die API normalisiert die Werte vor dem Speichern.

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
| `GET /calendar/{id}` | Nutzer muss das Event sehen dürfen → sonst `404` |
| `PUT /calendar/{id}` | Nur der Ersteller (oder Admin) → sonst `403` |
| `DELETE /calendar/{id}` | Nur der Ersteller (oder Admin) → sonst `403` |
| `POST /calendar/{id}/participants` | Nur der Ersteller (oder Admin) |
| `DELETE /calendar/{id}/participants/{userId}` | Nur der Ersteller (oder Admin) |

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/calendar` | JWT | Paginierte Liste der sichtbaren Kalender-Events (mit Zeitfilter) |
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
| `start` | ISO 8601 | Manueller Start der Zeitspanne (z. B. `2026-06-01T00:00:00Z`) |
| `end` | ISO 8601 | Manuelles Ende der Zeitspanne |
| `range` | `week` oder `month` | Vordefinierter Bereich (aktuelle Woche / aktueller Monat). Wird ignoriert wenn `start` + `end` gesetzt sind |

**Beispiele:**
```
GET /calendar?page=1&limit=20
GET /calendar?start=2026-06-01T00:00:00Z&end=2026-06-30T23:59:59Z
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
    "startTime": "2026-07-01T10:00:00.000Z",
    "endTime": "2026-07-01T11:00:00.000Z",
    "visibility": 1,
    "participants": [
      { "id": "uuid", "displayName": "Max", "image": null }
    ],
    "createdAt": "2026-06-26T10:00:00.000Z",
    "updatedAt": "2026-06-26T10:00:00.000Z"
  }
}
```

### `POST /calendar` – Request

```json
{
  "title": "Team Meeting",
  "description": "Wöchentliches Sync",
  "startTime": "2026-07-01T10:00:00Z",
  "endTime": "2026-07-01T11:00:00Z",
  "visibility": 1,
  "participants": ["user-uuid-1", "user-uuid-2"]
}
```

`participants` ist optional. Die hinzugefügten Teilnehmer erhalten eine
Push-Benachrichtigung.

### `PUT /calendar/{id}` – Request (partielles Update)

Nur die zu ändernden Felder mitsenden:

```json
{
  "title": "Geändertes Meeting",
  "startTime": "2026-07-01T14:00:00Z"
}
```

Alle Teilnehmer erhalten eine Push-Benachrichtigung über die Änderung.

### `POST /calendar/{id}/participants` – Request

```json
{
  "userId": "uuid-des-hinzuzufügenden-nutzers"
}
```

Der hinzugefügte Nutzer erhält eine Push-Benachrichtigung.

## Benachrichtigungen

Folgende Benachrichtigungs-Codes werden von der Kalender-Funktion verwendet:

| Code | Auslöser | Empfänger |
|------|----------|-----------|
| `calendar.event_created` | Neues Event mit Teilnehmern | Hinzugefügte Teilnehmer |
| `calendar.event_updated` | Event geändert | Alle Teilnehmer (außer dem Ersteller) |
| `calendar.participant_added` | Teilnehmer hinzugefügt | Der hinzugefügte Nutzer |

Beim Löschen (`DELETE /calendar/{id}`) wird keine Benachrichtigung versendet.

## SQL-Migration

Die Migration befindet sich in `events/calendar_schema.sql`:

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
