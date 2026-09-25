# Chat / Direktnachrichten – Centrifugo v6

1:1-Direktnachrichten und Gruppenchats zwischen Nutzern. Text-only (Bilder/Standort vorbereitet, aber nicht implementiert).

## Architektur-Überblick

```
Sender-Client ──REST──► PHP-API (persist, validate)
                         │ ──publish──► Centrifugo (chat.sinclear.de) ──WS/SSE──► Empfänger-Clients
                         │ ──presence──► Centrifugo (Push-Suppression)
Centrifugo ──Subscribe-Proxy──► POST /api/v2/centrifugo/subscribe (PHP prüft ChatParticipant)
Client ──WS publish (typing)──► Centrifugo ──Publish-Proxy──► PHP (validiert) ──► Broadcast
```

Die API ist die Source of Truth. Centrifugo ist Transport, Recovery und Presence – kein Persistenz-Backend.

### Kern-Entscheidungen

| Thema | Entscheidung | Begründung |
|---|---|---|
| **Persistenz** | API-DB bleibt Source of Truth | 90-Tage-Retention, Unread-Counts, Moderation, Notifications funktionieren weiter; Centrifugo ist nur Transport/Recovery |
| **Sende-Flow** | REST + Server-API Publish | Idiomatisch (Centrifugo Design Doku), graceful degradation, Response enthält finale Message |
| **Push-Suppression** | Conversation-Presence | `presence(chat:<convId>)` prüft, ob Empfänger den Chat *gerade offen* hat (vs. bisher: App-weit aktiv) |
| **PHP-Client** | Eigene schlanke Implementierung | Guzzle ^7.9 bereits im Projekt; keine neue Dependency; Shared Hosting bleibt ausreichend |
| **Hosting Clients** | Direkt an `chat.sinclear.de` (WS/SSE) | PHP-Server hält **nie** lange Verbindungen |

Siehe auch: [Centrifugo Server Deploy-Handbuch](./centrifugo.md)

## Channel-Modell

| Channel | Namespace | Zweck | Optionen (Centrifugo `config.json`) |
|---|---|---|---|
| `chat:<conversationId>` | `chat` | Chat-Echtzeit + Presence pro Konversation | `subscribe_proxy_enabled: true`, `publish_proxy_enabled: true`, `presence: true`, `join_leave: true`, `force_push_join_leave: true`, `force_recovery: true`, `force_positioning: true`, `history_size: 100`, `history_ttl: "600s"`, `channel_regex: "^[A-Za-z0-9_-]+$"`, `publication_data_format: "json"` |
| `user:<userId>` | `user` | Nutzer-Präsenz (nur Chat-Clients) | `subscribe_proxy_enabled: true`, `presence: true`, `join_leave: true`, `force_push_join_leave: true`, `channel_regex: "^[A-Za-z0-9_-]+$"`, `publication_data_format: "json"` |

## Authentifizierung

### Connection-JWT (HS256)

- **Secret**: `CENTRIFUGO_HMAC_SECRET` (eigenes Secret, nicht RSA)
- **Claims**: `sub` (userId als String), `exp` (TTL aus `CENTRIFUGO_TOKEN_TTL`, Standard 900s), `iat`, `iss: "sinclear-api"`, `aud: "centrifugo"`
- **Client refresht Token** via SDK `getToken` Callback → `GET /chat/centrifugo/token`
- **Server-seitig**: `client.token.audience: "centrifugo"`, `client.token.issuer: "sinclear-api"` konfiguriert

### Token-Endpoint

```
GET /api/v2/chat/centrifugo/token
Authorization: Bearer <JWT>

Response 200:
{
  "data": {
    "token": "<centrifugo-connection-jwt>",
    "url": "wss://chat.sinclear.de/connection/websocket",
    "expiresAt": "2026-01-15 10:15:00"
  }
}
```

`expiresAt` im Format `YYYY-MM-DD HH:MM:SS` (UTC). Clients nutzen dieses Endpoint als SDK `getToken` Callback für Token-Refresh.

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `ChatConversation` | Konversation (type: direct/group), `name` (optional), `image` (optional, base64) |
| `ChatParticipant` | Teilnehmer + `lastReadSeq` (DEFAULT 0) + `lastSeenAt` (NULL) |
| `DirectMessage` | Nachrichten mit `seq` (globaler Sync-Cursor), `clientId` (Idempotenz), `senderId`, `type`, `content`, `payload`, `editedAt`, `deletedAt` |
| `MessageReaction` | Reaktion (`emoji`) eines Nutzers auf eine Nachricht; UNIQUE `(messageId, userId, emoji)`, FK auf `DirectMessage`/`User` (ON DELETE CASCADE) |
| `TravelChat` | Verknüpfung von Gruppenchat mit Reise oder Event |

**PK von `ChatParticipant`:** `(conversationId, userId)`

*Entfernt (nicht mehr benötigt): `ChatEvent`, `ChatPresence`, `ChatTyping` – plus Legacy-Tabellen `ChatMessages`, `ChatRooms`, `ChatRoomMembers`, `ChatReadReceipt`, `DirectChat`, `UserPresence`, `SseEvent` (per Migration `20260916120000_drop_chat_realtime_tables.sql` gelöscht)*

### Gruppenchat (type: group)

Gruppenchats werden aktuell nur admin-seitig für Reisen und Events erstellt. Manuelle User-Gruppenchats sind geplant, aber nicht implementiert.

- `ChatConversation.type = 'group'` + `ChatConversation.name = Reise-/Event-Name`
- `ChatParticipant`-Einträge werden aus `TravelRelation`/`EventRelation` gespiegelt
- `TravelChat`-Tabelle speichert die Zuordnung (Reise oder Event)
- Bei Hinzufügen/Entfernen von Teilnehmern wird `ChatParticipant` automatisch synchronisiert
- Teilnehmer werden bei Entfernung automatisch vom Centrifugo-Channel abgemeldet (`unsubscribe`)
- `otherUser` ist `null` bei Gruppen; `otherLastReadSeq` ist `null` bei Gruppen
- `memberCount` enthält die Anzahl der aktiven Teilnehmer

## DTO-Schemas

Die vollständigen Schemas leben in `openapi.yaml`. Hier die Feldreferenz als Kurzübersicht.

### ChatConversation

Wird von `GET /chat/conversations` (Liste) und `GET/POST /chat/conversations/{id}` (Detail) zurückgegeben. Beide Endpoints liefern **dasselbe Feldset**.

**1:1-Konversation (direct):**

```json
{
  "id": "uuid",
  "type": "direct",
  "name": null,
  "image": null,
  "otherUser": {
    "id": "uuid",
    "displayName": "Alice",
    "avatar": "https://..."
  },
  "lastMessage": {
    "content": "Hallo!",
    "senderId": "uuid",
    "createdAt": "2026-01-15 10:30:00",
    "deleted": false
  },
  "unreadCount": 3,
  "lastSeenAt": "2026-01-15 10:25:00",
  "lastReadSeq": 42,
  "otherLastReadSeq": 38,
  "memberCount": null,
  "createdAt": "2026-01-15 10:00:00",
  "updatedAt": "2026-01-15 10:30:00"
}
```

**Gruppenchat (group):**

```json
{
  "id": "uuid",
  "type": "group",
  "name": "Sommerurlaub 2026",
  "image": "data:image/jpeg;base64,...",
  "otherUser": null,
  "lastMessage": { "..." },
  "unreadCount": 5,
  "lastSeenAt": null,
  "lastReadSeq": 42,
  "otherLastReadSeq": null,
  "memberCount": 4,
  "createdAt": "2026-06-01 09:00:00",
  "updatedAt": "2026-06-15 14:00:00"
}
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | string (uuid) | Konversations-ID |
| `type` | string | `direct` oder `group` |
| `name` | string\|null | Name (für Gruppen, null bei 1:1) |
| `image` | string\|null | Icon/Avatar der Gruppenkonversation (base64-encoded Bild); null bei 1:1 und wenn nicht gesetzt |
| `otherUser` | object\|null | Der andere Teilnehmer (`id`, `displayName`, `avatar`); null bei Gruppen |
| `lastMessage` | object\|null | Vorschau der letzten Nachricht (null wenn keine) |
| `unreadCount` | int | Anzahl ungelesener Nachrichten (`seq > lastReadSeq`) |
| `lastSeenAt` | string\|null | Letzter Seitenaufruf des anderen Teilnehmers; null bei Gruppen |
| `lastReadSeq` | int | Eigener Lesestand (höchster gelesener seq) |
| `otherLastReadSeq` | int\|null | Lesestand des Gegenübers; null bei Gruppen |
| `memberCount` | int\|null | Anzahl der Teilnehmer (nur bei Gruppen; null bei 1:1) |
| `createdAt` | string | Erstellungszeitpunkt (UTC) |
| `updatedAt` | string | Zeitpunkt der letzten Aktivität (UTC) |

### DirectMessage

```json
{
  "id": "uuid",
  "seq": 42,
  "conversationId": "uuid",
  "senderId": "uuid",
  "sender": {
    "id": "uuid",
    "displayName": "Alice",
    "avatar": "https://..."
  },
  "type": "text",
  "content": "Hallo!",
  "payload": null,
  "clientId": "client-123",
  "editedAt": null,
  "deleted": false,
  "reactions": [
    {
      "emoji": "👍",
      "count": 2,
      "users": [
        {"id": "uuid-1", "displayName": "Alice", "avatar": "https://..."},
        {"id": "uuid-2", "displayName": "Bob", "avatar": null}
      ]
    }
  ],
  "createdAt": "2026-01-15 10:30:00"
}
```

- `content`: Leerstring wenn `deleted == true`
- `payload`: null wenn `deleted == true` oder `type == text`
- `sender`: Engeschachteltes Objekt mit Absender-Details (aus User-Tabelle)
- `reactions`: aggregierte Reaktionen (siehe [Reaktionen](#reaktionen)); leer bei gelöschten Nachrichten. Das eigene Reagieren leitet der Client durch Abgleich der eigenen User-ID mit `users` ab (kein eigenes `me`-Feld).

## REST-API

### Authentifizierte Endpoints (`AuthenticationMiddleware`)

| Methode | Pfad | Zweck |
|---|---|---|
| **GET** | **`/chat/centrifugo/token`** | Centrifugo Connection-Token (SDK `getToken` Callback) |
| **GET** | **`/chat/presence`** | Nutzer-Präsenz abfragen (Bulk: `?userIds[]=...`) |
| **GET** | **`/chat/presence/{userId}`** | Einzelnen Nutzer-Status abfragen |
| GET | `/chat/conversations` | Konversationsliste (letzte Nachricht, Unread, lastSeenAt) |
| POST | `/chat/conversations` | 1:1-Konversation öffnen (idempotent: get-or-create) → 200 (bestehend) oder 201 (neu) |
| GET | `/chat/conversations/{id}` | Konversation + Teilnehmer (lastReadSeq, otherLastReadSeq) |
| GET | `/chat/conversations/{id}/messages?before=<seq>&limit=50` | History (Cursor `before`, max 100) |
| POST | `/chat/conversations/{id}/messages` | Senden (`{clientId, type, content, payload?}`) |
| PATCH | `/chat/messages/{id}` | Bearbeiten (nur eigener, 10 Min-Fenster) |
| DELETE | `/chat/messages/{id}` | Löschen für alle (Platzhalter "Nachricht gelöscht") |
| POST | `/chat/conversations/{id}/read` | Lesestand setzen (`{seq}`) |

### Interne Proxy-Endpoints (von Centrifugo aufgerufen, kein JWT)

| Methode | Pfad | Security | Zweck |
|---|---|---|---|
| POST | `/centrifugo/subscribe` | `X-Centrifugo-Proxy-Key` + HTTPS | Subscribe-Validierung (ChatParticipant) |
| POST | `/centrifugo/publish` | `X-Centrifugo-Proxy-Key` + HTTPS | Typing- und Reaktions-Validierung (Participant + Rate-Limit) |

**Security:** Kein JWT – gesichert via Shared Secret (`CENTRIFUGO_PROXY_KEY`) in `X-Centrifugo-Proxy-Key` Header (Centrifugo sendet via `http.static_headers`). HTTPS erzwungen durch vorgeschaltetes `RequireHttpsMiddleware`; die Shared-Secret-Prüfung liegt in `CentrifugoProxyMiddleware`.

### Admin: Travel-Gruppenchats

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise erstellen (idempotent) |
| DELETE | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise löschen |
| PATCH | `/admin/travel/trips/{id}/chat` | Gruppenchat-Icon für Reise setzen/entfernen |
| POST | `/admin/travel/events/{id}/chat` | Gruppenchat für Event erstellen (idempotent) |
| DELETE | `/admin/travel/events/{id}/chat` | Gruppenchat für Event löschen |
| PATCH | `/admin/travel/events/{id}/chat` | Gruppenchat-Icon für Event setzen/entfernen |

**Verhalten:**
- Bei Erstellung wird `ChatConversation` mit `type=group` + Name = Reise-/Event-Name angelegt
- `TravelChat`-Eintrag verknüpft den Chat mit Reise oder Event
- `ChatParticipant` wird aus den aktuellen Reise-/Event-Teilnehmern gespiegelt (via `TravelRelation`/`EventRelation`)
- Idempotent: GET oder POST gibt den bestehenden Chat zurück
- Bei Löschung werden `TravelChat`, `ChatConversation` und assoziierte `ChatParticipant`/`DirectMessage` gelöscht (FK-Cascade)
- Automatischer Sync: `AdminController` ruft `syncTripMembers`/`syncEventMembers` bei Hinzufügen/Entfernen von Teilnehmern auf
- **Entfernte Teilnehmer werden automatisch vom Centrifugo-Channel abgemeldet** (`CentrifugoClient->unsubscribe`)

## Message-Flow

### Senden einer Nachricht

```
1. Client sendet POST /chat/conversations/{id}/messages
2. PHP-API: Validierung (Content, Rate-Limit 20/min, Idempotenz via clientId)
3. PHP-API: Persistenz in DirectMessage (seq wird automatisch zugewiesen)
4. PHP-API: CentrifugoServer-API publish → chat:{conversationId} (message_created-Event)
5. PHP-API: Presence-Check → Empfänger im Channel? → suppressPush
6. PHP-API: Notification erstellen (in-App immer, Push nur wenn nicht online)
7. PHP-API: Response an Sender mit finalem Message-Objekt
8. Centrifugo: Distribuiert an alle abonnierten Clients (WS/SSE)
```

### Empfangen von Nachrichten

```
1. Client verbindet via WebSocket zu chat.sinclear.de
2. Client erhält Connection-JWT via GET /chat/centrifugo/token
3. Client abonniert Channel chat:{conversationId}
4. Centrifugo ruft POST /centrifugo/subscribe (PHP prüft ChatParticipant)
5. Client empfängt Echtzeit-Events (message_created, message_edited, message_deleted, read, reaction_updated)
```

## Typing via Publish-Proxy

```
1. Client sendet Typing-Event via Centrifugo WS publish
   Channel: chat:{conversationId}
   Data: { "typing": true }
2. Centrifugo ruft POST /centrifugo/publish
3. PHP-API: Validiert Participant + Rate-Limit (30/min)
4. PHP-API: Sanitized Response → Centrifugo broadcastet an andere Clients
```

**Rate-Limit:** 30 Typing-Events pro Minute pro Nutzer (Key: `chat_typing:{userId}`).

**Validierung:** Nur `typing: bool` wird akzeptiert (sanitized). Anderes `data` wird ignoriert.

**History:** Die Proxy-Antwort setzt `skip_history: true` – Typing-Events sind ephemer und werden nicht in der Channel-History gespeichert (kein Replay als „verpasste“ Nachricht bei Recovery).

**Channel-Validierung:** `parseConversationId` prüft Kanal gegen Regex `^chat:([A-Za-z0-9_-]+)$` → `400 invalid_channel` bei Nichteinhaltung.

## Reaktionen

Reaktionen laufen – wie Typing – vollständig über den **Publish-Proxy**, nicht über REST. Es gibt bewusst keinen REST-Endpoint: Der Client entscheidet lokal, ob er eine Reaktion hinzufügt oder entfernt, und persistiert+routet sie über die bestehende WS-Verbindung.

```
1. Client sendet via Centrifugo WS publish
   Channel: chat:{conversationId}
   Data: { "reaction": { "messageId": "<uuid>", "emoji": "👍", "add": true } }
2. Centrifugo ruft POST /centrifugo/publish
3. PHP-API: Participant-Check + Rate-Limit (60/min) + Persistenz (MessageReaction)
4. PHP-API: berechnet die aktualisierte Summary und antwortet
   { "result": { "data": { "type": "reaction_updated", "messageId": "...", "reactions": [ ... ] },
                 "skip_history": true } }
5. Centrifugo broadcastet die Summary an alle Subscriber (inkl. Sender)
```

- **Explizites `add`** statt „toggle": Wiederholte Zustellung (Proxy-Retry) ist damit idempotent (Add = `INSERT … ON DUPLICATE KEY UPDATE`, Remove = `DELETE`).
- **Rate-Limit:** 60 Reaktionen pro Minute pro Nutzer (Key: `chat_reaction:{userId}`).
- **Fehler:** `invalid_emoji` (nicht in Allowlist), `message_not_found` (unbekannt oder falsche Konversation), `message_deleted` → HTTP 400; Nicht-Teilnehmer → 403.
- **Ephemer:** `skip_history: true`. Bei Reconnect lädt der Client den Verlauf per REST neu; die Reaktionen sind dort in der Nachricht eingebettet.
- **Gelöschte Nachrichten** liefern `reactions: []`; beim Löschen werden die Reaktionszeilen entfernt.
- **Allowlist (normalisiert, ohne Variation Selectors):** 👍 ❤ 😂 😮 😢 🎉 🔥 👏.
  Clients senden dasselbe Set; `❤️` und `❤` werden serverseitig auf `❤` normalisiert.
- **Aggregation:** `reactions` ist nach Emoji gruppiert (`{emoji, count, users:[{id,displayName,avatar}]}`),
  sortiert nach `count` absteigend. Gruppenchats zeigen so mehrere Reagierende.
- **Persistenz:** Tabelle `MessageReaction`; die Summary wird in `DirectMessageService::formatMessage()`
  in jede Nachricht eingebettet (Batch-Load in `getMessages`, kein N+1).

## Echtzeit-Events

Clients empfangen folgende Events über den Centrifugo-Channel `chat:<conversationId>`:

### `message_created`

Neue Nachricht. Enthält das vollständige `DirectMessage`-Objekt (gleiches Schema wie REST-Response).

```json
{
  "type": "message_created",
  "message": {
    "id": "uuid", "seq": 42, "conversationId": "uuid",
    "senderId": "uuid", "sender": {"id": "...", "displayName": "...", "avatar": "..."},
    "type": "text", "content": "Hallo!", "payload": null,
    "clientId": "...", "editedAt": null, "deleted": false, "createdAt": "2026-..."
  }
}
```

### `message_edited`

Nachricht wurde bearbeitet. Enthält das aktualisierte `DirectMessage`-Objekt.

```json
{
  "type": "message_edited",
  "message": { "... DirectMessage ..." }
}
```

### `message_deleted`

Nachricht wurde gelöscht.

```json
{
  "type": "message_deleted",
  "messageId": "uuid",
  "seq": 42
}
```

### `read`

Lesestand-Update eines Teilnehmers. Wird mit `skip_history: true` publiziert (ephemer, kein Replay bei Recovery).

```json
{
  "type": "read",
  "seq": 42,
  "lastReadSeq": 42,
  "userId": "uuid"
}
```

### `reaction_updated`

Eine Reaktion wurde hinzugefügt oder entfernt. Enthält die vollständige, neu
aggregierte Reaktions-Summary der betroffenen Nachricht. Wird mit
`skip_history: true` publiziert (ephemer; REST-Verlauf ist maßgeblich).

```json
{
  "type": "reaction_updated",
  "messageId": "uuid",
  "reactions": [
    {
      "emoji": "👍",
      "count": 2,
      "users": [
        {"id": "uuid-1", "displayName": "Alice", "avatar": "https://..."},
        {"id": "uuid-2", "displayName": "Bob", "avatar": null}
      ]
    }
  ]
}
```

## Presence / Recovery / History

### Presence (Push-Suppression)

- Centrifugo liefert Presence **nach Client-ID** verschlüsselt: `{"<clientId>": {"client": "<clientId>", "user": "<userId>"}}`.
- `CentrifugoClient::presence("chat:<convId>")` **normalisiert** das auf User-IDs (dedupliziert, mehrere Verbindungen eines Nutzers → ein Eintrag): `{"<userId>": {"client": "...", "user": "<userId>"}}`.
- `array_keys($presence)` ergibt damit die online User-IDs → Vergleich gegen `ChatParticipant.userId` funktioniert.
- Empfänger im Channel → `suppressPush=true` (kein Push, aber In-App-Notification)
- Bei Centrifugo-Fehler → `presence()` gibt `[]` zurück → `suppressPush=false` (Fallback: Push senden)
- **Keine Personal-Channels** nötig

### Recovery

- Centrifugo `force_recovery: true` → Clients erhalten verpasste Nachrichten bei Reconnect
- `history_size: 100`, `history_ttl: "600s"` → letzte 100 Nachrichten für 10 Minuten gespeichert

### History

- REST-Endpoint `GET /chat/conversations/{id}/messages?before=<seq>&limit=50` für historische Nachrichten
- Cursor-basierte Pagination (nach `seq`)
- `lastSeenAt` wird bei jedem Abruf von Nachrichten aktualisiert

## Push-Unterdrückung (neue Semantik)

| Altes Verhalten | Neues Verhalten |
|---|---|
| `ChatPresence.activeUntil` prüft App-weit aktiv | `CentrifugoClient->presence()` prüft Chat geöffnet |
| Polling-basiert (5s Intervall) | WebSocket-basiert (sofortige Erkennung) |
| `ChatPresence`-Tabelle | Centrifugo Presence API |

**Begründung:** Conversation-Presence ist präziser – ein Nutzer kann die App im Hintergrund haben, aber den Chat nicht offen. In diesem Fall soll ein Push gesendet werden.

**Vollständiger Flow:**
1. `presence("chat:<convId>")` gibt assoziatives Array `{userId => {...}}` zurück
2. `array_keys($presence)` ergibt die Online-User-IDs
3. Für jeden Nicht-Sender-Teilnehmer: `suppressPush = in_array(userId, onlineUserIds)`
4. `NotificationService->create(..., suppressPush)` erstellt In-App-Record immer, sendet Push nur wenn `suppressPush=false`

## Graceful Degradation

- **CENTRIFUGO_ENABLED=false** → Alle Centrifugo-Calls sind No-ops (keine Fehler, kein Publish, `presence()` gibt `[]` zurück)
- **Fehler:** Niemals fatal – nur loggen, Chat funktioniert via REST weiter
- **Timeout:** `CENTRIFUGO_TIMEOUT` (~2s) für alle Server-API-Calls
- **Fallback bei Presence-Fehler:** Push senden (sicherer als nicht senden)
- **CentrifugoClient** fängt `GuzzleException` ab, loggt Warning, gibt `[]` zurück
- **ChatEventPublisher** fängt `\Throwable` ab, loggt Warning, wirft nie

## Lesestatus (Read Receipts)

- Pro Teilnehmer wird `lastReadSeq` geführt. Der Sender sieht „gelesen", sobald `otherLastReadSeq >= msg.seq`.
- `POST /chat/conversations/{id}/read` setzt den eigenen Lesestand; das `seq` wird serverseitig auf das Maximum der Konversation begrenzt (`GREATEST(lastReadSeq, newSeq)`).
- Read-Event wird via Centrifugo an andere Clients gepublished (`read`-Event mit `seq`, `lastReadSeq`, `userId`).

## Idempotenz

- `POST …/messages` mit `clientId`: **UNIQUE-Constraint** `(senderId, clientId)` + serverseitiger Lookup innerhalb derselben Konversation verhindern Duplikate bei Retry (auch unter parallelen Requests). Ein wiederverwendetes `clientId` in einer anderen Konversation wird als neuer Sendeversuch behandelt.
- **Concurrent-Duplikate:** Bei PDO-Exception `23000` (Unique-Verletzung) wird das existierende Objekt zurückgegeben.

## Nachrichten-Aktionen

- **Bearbeiten**: Nur eigener Sender, innerhalb 10 Minuten (`EDIT_WINDOW_SECONDS = 600`). Setzt `editedAt` und published `message_edited`-Event via Centrifugo.
- **Löschen für alle**: Nur Sender. `deletedAt` gesetzt, `content`/`payload` geleert. Empfänger sieht Platzhalter. Published `message_deleted`-Event via Centrifugo. Idempotent (bereits gelöschte Nachricht → kein Fehler).
- **Gelesen**: `lastReadSeq` pro Teilnehmer. Sender sieht "gelesen" wenn `empfänger.lastReadSeq >= msg.seq`. Published `read`-Event via Centrifugo.

## Validierung und Limits

| Regel | Detail |
|---|---|
| Content | Nicht-leer, max. 2000 Zeichen (`MAX_CONTENT_LENGTH`) |
| Type | Nur `text` erlaubt (`VALID_TYPES = ['text']`) |
| Payload | Bei `type: text` abgelehnt (`invalid_payload`) |
| Rate-Limit Senden | 20 Nachrichten/Minute pro Nutzer (Key: `chat_send:{userId}`) |
| Rate-Limit Typing | 30 Events/Minute pro Nutzer (Key: `chat_typing:{userId}`) |
| Rate-Limit Reaktion | 60 Events/Minute pro Nutzer (Key: `chat_reaction:{userId}`) |
| Emoji | Nur Allowlist (normalisiert), sonst `invalid_emoji` |
| Reaktion auf gelöschte Nachricht | Abgelehnt (`message_deleted`) |
| Edit-Fenster | 10 Minuten nach `createdAt` (UTC) |
| Delete | Kein Zeitfenster, idempotent |
| Idempotenz | `clientId` → UNIQUE `(senderId, clientId)` |
| read `seq` | Clamped auf `min(max(seq, 0), maxSeq)` |

**Antwort bei Überschreitung:** `429 rate_limit_exceeded`

## Notifications

- Typ `direct_message` in `NotificationService::CONTENT_TEMPLATES`
- **Titel**: `Neue Nachricht` (API-generiert)
- **Body**: `"{Absender}: {Vorschau}"` (Vorschau auf 160 Zeichen gekürzt; bei leerem Content: `"{Absender} hat dir eine Nachricht geschickt."`)
- **Bündelung**: Eine Notification pro Konversation (`dedupeKey = "chat:<conversationId>"`)
- Coalesced Upsert: Existierende ungelesene Notification wird aktualisiert statt neu anzulegen
- Denylist-Präferenz: `direct_message` mit `customData.userIds` (wie `story_post`)
- **Push-Unterdrückung**: Empfänger im Centrifugo-Channel → kein Push (aber In-App-Listeneintrag)
- **Gruppenchats**: Alle Nicht-Sender-Teilnehmer erhalten eine Notification (kein Unterschied zu 1:1)

## Moderation

Chat-Nachrichten haben aktuell **keinen** `ModerationObjectType`. Das `ModerationRequest.objectType`-ENUM enthält (produktiv, verifiziert):

```
user, forum_post, recipe, explore_place
```

`chat_message` fehlt. Laut AGENTS.md muss jeder User-Content einen Report-Flag haben. **TODO:** `chat_message` zum ENUM + `VALID_OBJECT_TYPES` hinzufügen und `resolveOwner` für Chat-Nachrichten implementieren. Der Report-Button für Chat kann ggf. auf die nächste Iteration verschoben werden.

## Cron

- `CleanupOldDirectMessagesTask`: Löscht Nachrichten älter als 90 Tage (gebatcht, LIMIT 1000)
- Räumt verwaiste Konversationen auf (keine Nachrichten, älter als 1 Tag)
- Intervall: 24 Stunden
- *Entfernt: ChatEvent, ChatPresence, ChatTyping Cleanup (Tabellen per Migration `20260916120000_drop_chat_realtime_tables.sql` gelöscht)*

## Gruppenchat-Synchronisation

Bei Reisen und Events wird `ChatParticipant` automatisch mit den aktuellen Mitgliedern synchronisiert:

1. **Reise:** `syncTripMembers` liest `TravelRelation`, spiegelt nach `ChatParticipant`
2. **Event:** `syncEventMembers` liest `EventRelation`, spiegelt nach `ChatParticipant`
3. **Entfernte Teilnehmer** werden aus `ChatParticipant` gelöscht **und** vom Centrifugo-Channel abgemeldet (`CentrifugoClient->unsubscribe("chat:<convId>", userId)`)

*Hinweis: Centrifugo hat keinen Unsubscribe-Hook – die API muss aktiv abmelden.*

## Migration (Datenbank)

Die Migration `20260916120000_drop_chat_realtime_tables.sql` löscht:

**Realtime-Tabellen (durch Centrifugo ersetzt):**
- `ChatEvent`, `ChatTyping`, `ChatPresence`

**Legacy-Tabellen (nicht mehr im Code referenziert):**
- `ChatRoomMembers`, `ChatRooms`, `ChatMessages`, `ChatReadReceipt`, `DirectChat`, `UserPresence`, `SseEvent`

**Legacy DB-Event:**
- `cleanup_messages`

*Keine neuen Tabellen nötig. Unread-Counts laufen über `DirectMessage.seq` + `ChatParticipant.lastReadSeq`.*

## Env-Vars

| Variable | Beschreibung | Standard |
|---|---|---|
| `CENTRIFUGO_API_URL` | Centrifugo Server API URL | – |
| `CENTRIFUGO_API_KEY` | API-Key für Server-API | – |
| `CENTRIFUGO_HMAC_SECRET` | Secret für Connection-JWTs (HS256) | – |
| `CENTRIFUGO_TOKEN_TTL` | JWT-Gültigkeitsdauer (Sekunden) | 900 |
| `CENTRIFUGO_WS_URL` | WebSocket-URL für Clients | – |
| `CENTRIFUGO_TIMEOUT` | Timeout für Server-API-Calls (Sekunden) | 2 |
| `CENTRIFUGO_ENABLED` | Aktivieren/Deaktivieren | false |
| `CENTRIFUGO_ISSUER` | JWT-Issuer | sinclear-api |
| `CENTRIFUGO_AUDIENCE` | JWT-Audience | centrifugo |
| `CENTRIFUGO_PROXY_KEY` | Shared Secret für Proxy-Endpoints | – |

## Deep-Links zu Centrifugo-Docs

- [Channels/Namespaces](https://centrifugal.dev/docs/server/channels)
- [Channel Token Auth](https://centrifugal.dev/docs/server/channel_token_auth)
- [Proxy](https://centrifugal.dev/docs/server/proxy)
- [Server API](https://centrifugal.dev/docs/server/server_api)
- [Authentication](https://centrifugal.dev/docs/server/authentication)
- [Design/Idiomatic Usage](https://centrifugal.dev/docs/getting-started/design)
- [Client API/SDK](https://centrifugal.dev/docs/transports/client_api)

## Flutter-SDK Referenz

- **Paket:** `centrifuge` 0.20.1 (pub.dev)
- **Token-Refresh:** via `getToken` Callback → `GET /chat/centrifugo/token`
- **Subscription:** `Subscription` auf `chat:<conversationId>`
- **Typing:** Client-seitiges Publish mit `{ typing: bool }`
