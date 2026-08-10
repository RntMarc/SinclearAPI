# Notifications API

Benachrichtigungs-System für Sinclear Beyond. Ermöglicht In-App-Benachrichtigungen via Polling sowie Push-Benachrichtigungen via Web Push (VAPID) und UnifiedPush.

## Endpunkte

### Ungelesene Benachrichtigungen abrufen

```
GET /notifications
```

Gibt alle ungelesenen Benachrichtigungen des eingeloggten Nutzers zurück (max. 50).

**Authentifizierung:** Erforderlich (Bearer Token)

**Query-Parameter:**
| Parameter | Typ | Beschreibung |
|-----------|-----|-------------|
| `since` | string | Optional. Zeitstempel `YYYY-MM-DD HH:MM:SS`. Nur Benachrichtigungen nach diesem Zeitpunkt. |

**Response 200:**
```json
{
  "notifications": [
    {
      "id": "01923456-7890-7abc-def0-123456789012",
      "userId": "user-id",
      "type": "forum_reply",
      "title": "Neue Antwort",
      "body": "Jemand hat auf deinen Beitrag geantwortet.",
      "data": { "route": "/forum/42" },
      "isRead": false,
      "createdAt": "2026-08-10 14:30:00"
    }
  ]
}
```

### Benachrichtigungen als gelesen markieren

```
POST /notifications/read
```

Markiert eine Liste von Benachrichtigungs-IDs als gelesen.

**Authentifizierung:** Erforderlich

**Request Body:**
```json
{
  "ids": ["notification-id-1", "notification-id-2"]
}
```

**Response 200:**
```json
{ "ok": true }
```

**Response 400:** `ids` fehlt, ist kein Array oder ist leer.

### Push-Subscription speichern

```
POST /notifications/push-subscription
```

Speichert oder aktualisiert eine Web Push- oder UnifiedPush-Subscription für den Nutzer.

**Authentifizierung:** Erforderlich

**Request Body (Web Push):**
```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "type": "webpush",
  "keys": {
    "p256dh": "BNcRdreALRFXTkOOUJC1eKdf...=",
    "auth": "tBH8hGMqGPV5xYtFzIgRwA=="
  }
}
```

**Request Body (UnifiedPush):**
```json
{
  "endpoint": "https://ntfy.sh/my-topic",
  "type": "unifiedpush"
}
```

**Response 201:**
```json
{ "ok": true }
```

**Response 400:**
- `endpoint_required`: Endpoint fehlt oder ist ungültig
- `type_invalid`: Type ist nicht `webpush` oder `unifiedpush`
- `webpush_keys_required`: Bei Web Push fehlen `p256dh` oder `auth`

### Push-Subscription löschen

```
DELETE /notifications/push-subscription
```

Löscht eine Push-Subscription des Nutzers.

**Authentifizierung:** Erforderlich

**Request Body:**
```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/..."
}
```

**Response 200:**
```json
{ "ok": true }
```

### VAPID Public Key abrufen

```
GET /notifications/vapid-public-key
```

Gibt den öffentlichen VAPID-Schlüssel für Web Push zurück. Keine Authentifizierung erforderlich.

**Response 200:**
```json
{
  "key": "BEl621UYB2..."
}
```

## Datenbank-Schema

### Tabelle `Notification`

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `id` | varchar(191) | Primärschlüssel (UUIDv7) |
| `userId` | varchar(191) | Empfänger (FK zu User) |
| `type` | varchar(64) | Typ der Benachrichtigung (z.B. `forum_reply`, `event_reminder`) |
| `title` | varchar(255) | Kurztitel |
| `body` | text | Anzeigetext |
| `data` | json | Optionale Routing-Daten (z.B. `{"route": "/forum/42"}`) |
| `isRead` | tinyint(1) | 0 = ungelesen, 1 = gelesen |
| `createdAt` | datetime(3) | Erstellungszeitpunkt (UTC) |

**Index:** `(userId, isRead, createdAt)` für schnelles Polling.

### Tabelle `PushSubscription`

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `id` | varchar(191) | Primärschlüssel (UUIDv7) |
| `userId` | varchar(191) | Eigentümer (FK zu User) |
| `type` | varchar(20) | `webpush` oder `unifiedpush` |
| `endpoint` | text | Push-Endpoint-URL |
| `p256dh` | text | Web Push Public Key (nullable) |
| `auth` | text | Web Push Auth Secret (nullable) |
| `userAgent` | varchar(255) | User-Agent des Clients (nullable) |
| `createdAt` | datetime(3) | Erstellungszeitpunkt (UTC) |

**Unique:** `endpoint` (Duplikate verhindern)

## Notification-Typen

| Typ | Beschreibung | Data-Format |
|-----|-------------|-------------|
| `forum_reply` | Antwort auf einen Forum-Beitrag | `{"route": "/forum/{id}"}` |
| `forum_comment` | Kommentar auf eine Antwort | `{"route": "/forum/{id}"}` |
| `event_reminder` | Erinnerung an einen Kalendereintrag | `{"route": "/calendar/{id}"}` |
| `poll_invitation` | Einladung zu einer Umfrage | `{"route": "/poll/{id}"}` |
| `travel_update` | Änderung an einer Reise | `{"route": "/trips/{id}"}` |
| `feedback_status` | Status-Änderung einer Feedback-Meldung | `{"route": "/feedback/{id}"}` |

## Push-Versand

### Web Push (VAPID)

Der API-Server sendet automatisch Web Push-Benachrichtigungen an alle registrierten Web Push-Subscriptions des Empfängers, wenn eine neue Benachrichtigung erstellt wird.

**Payload:**
```json
{
  "id": "01923456-7890-7abc-def0-123456789012",
  "type": "forum_reply",
  "title": "Neue Antwort",
  "body": "Jemand hat auf deinen Beitrag geantwortet.",
  "data": { "route": "/forum/42" },
  "createdAt": "2026-08-10 14:30:00.000"
}
```

Abgelaufene Endpoints (HTTP 410) werden automatisch aus der Datenbank entfernt.

### UnifiedPush

UnifiedPush-Endpoints werden per HTTP POST mit JSON-Body bedient. Der Distributor (ntfy, Gotify UP, etc.) leitet die Nachricht an das Gerät weiter.

**Payload:**
```json
{
  "id": "01923456-7890-7abc-def0-123456789012",
  "type": "forum_reply",
  "title": "Neue Antwort",
  "body": "Jemand hat auf deinen Beitrag geantwortet.",
  "data": { "route": "/forum/42" },
  "createdAt": "2026-08-10 14:30:00.000"
}
```

HTTP 410 vom Distributor → Subscription wird automatisch gelöscht.

> Der Payload entspricht 1:1 dem JSON-Objekt aus `GET /notifications` (ohne `userId`/`isRead`), damit Clients die Nachricht mit demselben Deserializer (z. B. `NotificationItem.fromJson`) parsen können.

### Bereinigung abgelaufener Subscriptions

Die Bereinigung erfolgt **reaktiv** beim Push-Versand: Endpoints, die mit HTTP 410 (oder 404) antworten, werden unmittelbar nach der Fehlerantwort aus `PushSubscription` gelöscht (`sendWebPush` via `MessageSentReport::isSubscriptionExpired()`, `sendUnifiedPush` bei HTTP 410). Ein separater `cleanExpiredSubscriptions()`-Sweep ist bewusst nicht implementiert, da der reaktive Weg keinen zusätzlichen Zustand benötigt und die Abfrage leben­der Endpoints im Batch unzuverlässig wäre.

## Integration in bestehende Controller

Benachrichtigungen werden serverseitig erstellt, indem der `NotificationService` aufgerufen wird:

```php
$this->notificationService->create(
    userId: $recipientUserId,
    type: 'forum_reply',
    title: 'Neue Antwort',
    body: 'Jemand hat auf deinen Beitrag geantwortet.',
    data: ['route' => '/forum/' . $postId],
);
```

## Konfiguration

### .env Variablen

```
VAPID_PUBLIC_KEY=<base64url-encoded-public-key>
VAPID_PRIVATE_KEY=<base64url-encoded-private-key>
VAPID_SUBJECT=mailto:contact@sinclear.de
```

Schlüsselpaar generieren mit:
```bash
php bin/generate_vapid_keys.php
```

### Erforderliche PHP-Erweiterungen

- `ext-curl` (für Web Push via minishlink/web-push)
- `ext-json` (für JSON-Payload)
