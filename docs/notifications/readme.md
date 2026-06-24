# Notifications API

## Übersicht

Das Benachrichtigungssystem unterstützt zwei Delivery-Mechanismen:

1. **FCM Push** (Android/Web): Der Client wird durch eine FCM-Nachricht aufgeweckt und ruft die Benachrichtigung via API ab.
2. **Polling** (Linux/Windows): Der Client ruft periodisch die API auf, um neue Benachrichtigungen abzurufen.

## Architektur

```
┌─────────────────────────────────────────────────────────┐
│                    NOTIFICATION FLOW                     │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  [Event triggert]                                       │
│       │                                                 │
│       ▼                                                 │
│  NotificationService.createNotification()               │
│       │                                                 │
│       ├──► Notification Tabelle (speichern)             │
│       │                                                 │
│       └──► PushService.sendToUser()                     │
│              │                                          │
│              └──► FCM HTTP v1 API (pro Device)          │
│                     │                                   │
│                     ▼                                   │
│  ┌──────────────────────────────────────┐               │
│  │  FCM Data Message (kein notification │               │
│  │  Feld!): { notificationId: "..." }  │               │
│  └──────────────────────────────────────┘               │
│                     │                                   │
│         ┌───────────┴───────────┐                       │
│         ▼                       ▼                       │
│  [Android Client]        [Web Client]                   │
│  FCM aufgeweckt          Service Worker                 │
│         │                       │                       │
│         ▼                       ▼                       │
│  GET /notifications/{id}  GET /notifications/{id}       │
│         │                       │                       │
│         ▼                       ▼                       │
│  [Anzeige generieren]    [Anzeige generieren]           │
│                                                         │
│  ─────────────────────────────────────────────────────  │
│                                                         │
│  POLLING FLOW (Linux/Windows):                          │
│                                                         │
│  [Event triggert]                                       │
│       │                                                 │
│       ▼                                                 │
│  NotificationService.createNotification()               │
│       │                                                 │
│       └──► Notification Tabelle (speichern)             │
│                                                         │
│  [Client Scheduler]──── GET /notifications?since=...    │
│                              │                          │
│                              ▼                          │
│                         [Anzeige generieren]            │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

## Datenbank

### Notification Tabelle

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | varchar(191) | Eindeutige Benachrichtigungs-ID (UUID v7) |
| `userId` | varchar(191) | Empfänger der Benachrichtigung |
| `type` | varchar(191) | Benachrichtigungstyp (z.B. `recipe_review`) |
| `entityId` | varchar(191) | ID der zugehörigen Entität |
| `createdAt` | datetime(3) | Erstellungszeitpunkt |

**Wichtig:** Benachrichtigungen werden nach dem Lesen gelöscht (Inbox-Pattern).

### UserDevice Tabelle (erweitert)

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | varchar(191) | Eindeutige Geräte-ID |
| `userId` | varchar(191) | Besitzer des Geräts |
| `deviceName` | varchar(191) | Optionale Bezeichnung |
| `platform` | varchar(50) | Plattform (`android`, `web`, `ios`, `linux`, `windows`) |
| `pushToken` | text | FCM Registration Token |
| `pushEnabled` | tinyint(1) | Push aktiviert (0/1) |
| `lastActiveAt` | datetime | Letzte Aktivität |
| `createdAt` | datetime | Registrierungszeitpunkt |

## API-Endpoints

### Benachrichtigungen

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
      "type": "recipe_review",
      "entityId": "01912345-6789-...",
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
    "type": "recipe_review",
    "entityId": "01912345-6789-...",
    "createdAt": "2025-01-15T10:30:00.000Z"
  }
}
```

#### `DELETE /notifications/{id}`

Löscht eine Benachrichtigung (markiert als gelesen).

**Antwort:** `204 No Content`

#### `DELETE /notifications`

Löscht alle Benachrichtigungen des Nutzers.

**Antwort:**
```json
{
  "data": {
    "deleted": 5
  }
}
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

Entfernt ein Gerät aus der Liste der Push-Empfänger.

**Antwort:** `204 No Content`

## FCM-Konfiguration

### Environment-Variablen

```
FCM_PROJECT_ID=dein-firebase-projekt
FCM_CLIENT_EMAIL=firebase-adminsdk-xxxxx@dein-projekt.iam.gserviceaccount.com
FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

### Firebase Setup

1. Firebase-Projekt erstellen unter https://console.firebase.google.com
2. Cloud Messaging für das Projekt aktivieren
3. Service Account erstellen und Private Key herunterladen
4. Werte in `.env` eintragen

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

## Integration in bestehende Features

### Benachrichtigung erstellen

```php
// In einem Service oder Controller:
$this->notificationService->createNotification(
    userId: $recipe->creatorId,
    type: 'recipe_review',
    entityId: $review->id
);
```

### Client-Flow (FCM)

1. Client registriert Gerät via `POST /notifications/devices`
2. FCM sendet Data-Message bei neuer Benachrichtigung
3. Client empfängt `notificationId` im Service Worker
4. Client ruft `GET /notifications/{id}` ab
5. Client generiert Anzeige basierend auf `type` und `entityId`

### Client-Flow (Polling)

1. Client ruft periodisch `GET /notifications?since=<letzterZeitstempel>` auf
2. API gibt neue Benachrichtigungen zurück
3. Client generiert Anzeige basierend auf `type` und `entityId`

## SQL-Migration

Die Migration befindet sich in `events/add_pushenabled_and_notification_cleanup.sql`:

```sql
-- pushEnabled Spalte zu UserDevice hinzufügen
ALTER TABLE `UserDevice`
  ADD COLUMN `pushEnabled` tinyint(1) NOT NULL DEFAULT '0' AFTER `pushToken`;

-- MySQL Event: Alte Benachrichtigungen aufräumen (älter als 30 Tage)
CREATE EVENT IF NOT EXISTS clean_old_notifications
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    DELETE FROM Notification
    WHERE createdAt < DATE_SUB(NOW(), INTERVAL 30 DAY);
END;
```
