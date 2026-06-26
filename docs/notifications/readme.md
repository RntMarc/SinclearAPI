# Notifications API

## Übersicht

Das Benachrichtigungssystem unterstützt zwei Delivery-Mechanismen:

1. **FCM Push** (Android/Web): Der Client wird durch eine FCM-Nachricht aufgeweckt und ruft die Benachrichtigung via API ab.
2. **Polling** (Linux/Windows): Der Client ruft periodisch die API auf, um neue Benachrichtigungen abzurufen.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC (beim Senden an die API) und von UTC in die lokale Zeitzone (beim Empfangen von der API) verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank

### Notification Tabelle (v2)

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | varchar(191) | Eindeutige Benachrichtigungs-ID (UUID v7) |
| `userId` | varchar(191) | Empfänger der Benachrichtigung |
| `code` | varchar(191) | Interner Benachrichtigungscode (siehe Code-Referenz) |
| `payload` | json | Kontextabhängiger Informationskatalog |
| `createdAt` | datetime(3) | Erstellungszeitpunkt |

**Inbox-Pattern:** Benachrichtigungen werden nach dem Lesen gelöscht (`DELETE`).

## Notification Codes

Jede Benachrichtigung hat einen `code` im Format `domain.action`. Der Client
rendert basierend auf dem Code die passende UI und verwendet Daten aus
`payload` für die Darstellung.

### Admin-Codes

| Code | Payload | Beschreibung |
|------|---------|-------------|
| `admin.system_update` | `{ "deepLink": "home" }` | System-Update-Ankündigung |
| `admin.new_feature` | `{ "deepLink": "home" }` | Neue Funktion verfügbar |
| `admin.maintenance` | `{ "deepLink": "home" }` | Wartungshinweis |
| `admin.welcome` | `{ "deepLink": "home" }` | Willkommensnachricht |
| `admin.test` | `{ "deepLink": "home" }` | Test-Ping |
| `admin.custom` | `{ "deepLink": "...", "title": "...", "body": "..." }` | Freie Admin-Nachricht |

**Deep-Link-Werte:** `home`, `travel`, `events`, `profile`, `settings`, `friends`, `discover`, `news`, `chat`, `feedback`

### Kalender-Codes

| Code | Payload | Beschreibung |
|------|---------|-------------|
| `calendar.event_created` | `{ "calendarEventId": "uuid", "title": "..." }` | Neues Kalender-Event mit Teilnehmern |
| `calendar.event_updated` | `{ "calendarEventId": "uuid", "title": "..." }` | Kalender-Event wurde geändert |
| `calendar.participant_added` | `{ "calendarEventId": "uuid", "title": "..." }` | Zu Kalender-Event hinzugefügt |

### Zukünftige Codes (Beispiele)

| Code | Payload | Beschreibung |
|------|---------|-------------|
| `changelog.new_entry` | `{ "changelogId": "uuid" }` | Neuer Changelog-Eintrag |
| `like.received` | `{ "actorId": "uuid", "postId": "uuid" }` | Jemand hat einen Beitrag geliked |
| `friend.request` | `{ "actorId": "uuid" }` | Freundschaftsanfrage |

## API-Endpoints

### Benachrichtigungen abrufen

#### `GET /notifications`

Gibt alle Benachrichtigungen des Nutzers zurück. Unterstützt Polling.

**Query-Parameter:**
- `since` (optional): ISO 8601 Zeitstempel - gibt nur Benachrichtigungen zurück, die danach erstellt wurden
- `limit` (optional): Maximale Anzahl (1-100, Standard: 50)

**Antwort:**
```json
{
  "data": [
    {
      "id": "01912345-6789-...",
      "code": "admin.system_update",
      "payload": { "deepLink": "home" },
      "createdAt": "2025-01-15T10:30:00.000Z"
    }
  ],
  "meta": {
    "unreadCount": 3
  }
}
```

#### `GET /notifications/{id}`

Gibt eine einzelne Benachrichtigung zurück.

**Antwort:**
```json
{
  "data": {
    "id": "01912345-6789-...",
    "code": "admin.system_update",
    "payload": { "deepLink": "home" },
    "createdAt": "2025-01-15T10:30:00.000Z"
  }
}
```

### Benachrichtigungen löschen (als gelesen markieren)

#### `DELETE /notifications/{id}`

Löscht eine Benachrichtigung (markiert als gelesen). Antwort: `204 No Content`

#### `DELETE /notifications`

Löscht alle Benachrichtigungen des Nutzers.

**Antwort:**
```json
{ "data": { "deleted": 5 } }
```

### Push-Geräte verwalten

#### `GET /notifications/devices`

Gibt alle registrierten Geräte des Nutzers zurück.

**Antwort:**
```json
{
  "data": [
    {
      "id": "01912345-6789-...",
      "deviceName": "Mein Android Handy",
      "platform": "android",
      "pushEnabled": true,
      "lastActiveAt": "2025-01-15T10:30:00.000Z",
      "createdAt": "2025-01-10T08:00:00.000Z"
    }
  ]
}
```

#### `POST /notifications/devices`

Registriert ein Gerät für Push-Benachrichtigungen.

**Request Body:**
```json
{
  "token": "fcm-registration-token-here",
  "platform": "android",
  "deviceName": "Mein Android Handy"
}
```

**Antwort:**
```json
{
  "data": {
    "id": "01912345-6789-...",
    "platform": "android",
    "pushEnabled": true
  }
}
```

#### `DELETE /notifications/devices/{deviceId}`

Entfernt ein Gerät. Antwort: `204 No Content`

## FCM-Konfiguration

### Environment-Variablen

```
FCM_PROJECT_ID=dein-firebase-projekt
FCM_CLIENT_EMAIL=firebase-adminsdk-xxxxx@dein-projekt.iam.gserviceaccount.com
FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

### FCM-Nachricht-Format

Die API sendet **Data-Only Messages** (kein `notification`-Feld):

```json
{
  "message": {
    "token": "<fcm-registration-token>",
    "data": {
      "notificationId": "<uuid>"
    }
  }
}
```

Der Client empfängt die Nachricht und ruft die Benachrichtigung via `GET /notifications/{id}` ab.

## Client-Flow

### FCM-Push
1. Client registriert Gerät via `POST /notifications/devices`
2. FCM sendet Data-Message bei neuer Benachrichtigung
3. Client empfängt `notificationId` im Service Worker
4. Client ruft `GET /notifications/{id}` ab
5. Client rendert Anzeige basierend auf `code` und `payload`

### Polling
1. Client ruft periodisch `GET /notifications?since=<letzterZeitstempel>` auf
2. API gibt neue Benachrichtigungen zurück
3. Client rendert Anzeige basierend auf `code` und `payload`

### Benachrichtigung erstellen (Backend)

```php
$this->notificationService->createNotification(
    userId: $targetUserId,
    code: 'admin.system_update',
    payload: ['deepLink' => 'home'],
);
```

## SQL-Migration

Die Migration für v2 befindet sich in `events/notification_schema_v2.sql`:
```sql
DROP TABLE IF EXISTS `Notification`;
CREATE TABLE `Notification` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `userId` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `createdAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_user_time` (`userId`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**ACHTUNG:** Das alte Schema (`type` + `entityId`) wird nicht mehr unterstützt.
