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

### Kern-Entscheidungen

| Thema | Entscheidung | Begründung |
|---|---|---|
| **Persistenz** | API-DB bleibt Source of Truth | 90-Tage-Retention, Unread-Counts, Moderation, Notifications funktionieren weiter; Centrifugo ist nur Transport/Recovery |
| **Sende-Flow** | REST + Server-API Publish | Idiomatisch (Centrifugo Design Doku), graceful degradation, Response enthält finale Message |
| **Push-Suppression** | Conversation-Presence | `presence(chat:<convId>)` prüft, ob Empfänger den Chat *gerade offen* hat (vs. bisher: App-weit aktiv) |
| **PHP-Client** | Eigene schlanke Implementierung | Guzzle ^7.9 bereits im Projekt; keine neue Dependency; Shared Hosting bleibt ausreichend |
| **Hosting Clients** | Direkt an `chat.sinclear.de` (WS/SSE) | PHP-Server hält **nie** lange Verbindungen |

## Channel-Modell

| Channel | Namespace | Optionen (Centrifugo `config.json`) |
|---|---|---|
| `chat:<conversationId>` | `chat` | `subscribe_proxy_enabled: true`, `publish_proxy_enabled: true`, `presence: true`, `join_leave: true`, `force_push_join_leave: true`, `force_recovery: true`, `force_positioning: true`, `history_size: 100`, `history_ttl: "600s"`, `channel_regex: "^[A-Za-z0-9_-]+$"`, `publication_data_format: "json"` |

*Keine Personal-Channels nötig (Entscheidung: Conversation-Presence).*

## Authentifizierung

### Connection-JWT (HS256)

- **Secret**: `CENTRIFUGO_HMAC_SECRET` (eigenes Secret, nicht RSA)
- **Claims**: `sub` (userId als String), `exp` (TTL aus `CENTRIFUGO_TOKEN_TTL`), `iat`, `iss: "sinclear-api"`, `aud: "centrifugo"`
- **Client refresht Token** via SDK `getToken` Callback → `GET /chat/centrifugo/token`
- **Server-seitig**: `client.token.audience: "centrifugo"`, `client.token.issuer: "sinclear-api"` konfiguriert

### Token-Endpoint

```
GET /api/v2/chat/centrifugo/token
Authorization: Bearer <JWT>

Response:
{
  "data": {
    "token": "<centrifugo-connection-jwt>",
    "url": "wss://chat.sinclear.de/connection/websocket",
    "expiresAt": "2025-01-15 10:15:00"
  }
}
```

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `ChatConversation` | Konversation (type: direct/group) |
| `ChatParticipant` | Teilnehmer + `lastReadSeq` + `lastSeenAt` |
| `DirectMessage` | Nachrichten mit `seq` (globaler Sync-Cursor) |
| `TravelChat` | Verknüpfung von Gruppenchat mit Reise oder Event |

*Entfernt (nicht mehr benötigt): `ChatEvent`, `ChatPresence`, `ChatTyping`*

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
  "otherUser": {
    "id": "uuid",
    "displayName": "Alice",
    "avatar": "https://..."
  },
  "lastMessage": {
    "content": "Hallo!",
    "senderId": "uuid",
    "createdAt": "2025-01-15 10:30:00",
    "deleted": false
  },
  "unreadCount": 3,
  "lastSeenAt": "2025-01-15 10:25:00",
  "lastReadSeq": 42,
  "otherLastReadSeq": 38,
  "memberCount": null,
  "createdAt": "2025-01-15 10:00:00",
  "updatedAt": "2025-01-15 10:30:00"
}
```

**Gruppenchat (group):**

```json
{
  "id": "uuid",
  "type": "group",
  "name": "Sommerurlaub 2025",
  "image": "data:image/jpeg;base64,...",
  "otherUser": null,
  "lastMessage": { "..." },
  "unreadCount": 5,
  "lastSeenAt": null,
  "lastReadSeq": 42,
  "otherLastReadSeq": null,
  "memberCount": 4,
  "createdAt": "2025-06-01 09:00:00",
  "updatedAt": "2025-06-15 14:00:00"
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
| `unreadCount` | int | Anzahl ungelesener Nachrichten |
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
  "createdAt": "2025-01-15 10:30:00"
}
```

- `content`: Leerstring wenn `deleted == true`
- `payload`: null wenn `deleted == true` oder `type == text`
- `sender`: Engeschachteltes Objekt mit Absender-Details (aus User-Tabelle)

## REST-API

| Methode | Pfad | Zweck |
|---|---|---|
| **GET** | **`/chat/centrifugo/token`** | **NEU:** Centrifugo Connection-Token (SDK `getToken` Callback) |
| GET | `/chat/conversations` | Konversationsliste (letzte Nachricht, Unread, lastSeenAt) |
| POST | `/chat/conversations` | 1:1-Konversation öffnen (idempotent: get-or-create) → 200 (bestehend) oder 201 (neu) |
| GET | `/chat/conversations/{id}` | Konversation + Teilnehmer (lastReadSeq, otherLastReadSeq) |
| GET | `/chat/conversations/{id}/messages?before=<seq>&limit=50` | History (Cursor `before`) |
| POST | `/chat/conversations/{id}/messages` | Senden (`{clientId, type, content, payload?}`) |
| PATCH | `/chat/messages/{id}` | Bearbeiten (nur eigener, 10 Min-Fenster) |
| DELETE | `/chat/messages/{id}` | Löschen für alle (Platzhalter "Nachricht gelöscht") |
| POST | `/chat/conversations/{id}/read` | Lesestand setzen (`{seq}`) |
| ~~POST~~ | ~~`/chat/conversations/{id}/typing`~~ | ~~Tippindikator~~ → **410 Gone** (Typing via Centrifugo Publish-Proxy) |
| ~~GET~~ | ~~`/chat/sync`~~ | ~~Optimierte Sync-Route~~ → **Deprecated**, antwortet leer |

### Interne Proxy-Endpoints (von Centrifugo aufgerufen)

| Methode | Pfad | Security | Zweck |
|---|---|---|---|
| POST | `/centrifugo/subscribe` | `X-Centrifugo-Proxy-Key` + HTTPS | Subscribe-Validierung (ChatParticipant) |
| POST | `/centrifugo/publish` | `X-Centrifugo-Proxy-Key` + HTTPS | Typing-Validierung (Participant + Rate-Limit) |

**Security:** Kein JWT – gesichert via Shared Secret (`CENTRIFUGO_PROXY_KEY`) in `X-Centrifugo-Proxy-Key` Header (Centrifugo sendet via `http.static_headers`).

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
- **Entfernte Teilnehmer werden automatisch vom Centrifugo-Channel abgemeldet**

## Message-Flow

### Senden einer Nachricht

```
1. Client sendet POST /chat/conversations/{id}/messages
2. PHP-API: Validierung (Content, Rate-Limit, Idempotenz via clientId)
3. PHP-API: Persistenz in DirectMessage (seq wird automatisch zugewiesen)
4. PHP-API: CentrifugoServer-API publish → chat:{conversationId}
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
5. Client empfängt Echtzeit-Events (message_created, message_edited, message_deleted, read)
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

## Presence / Recovery / History

### Presence (Push-Suppression)

- `CentrifugoClient->presence("chat:<convId>")` prüft, ob Empfänger den Chat *gerade offen* hat
- Empfänger im Channel → `suppressPush=true` (kein Push, aber In-App-Notification)
- Bei Centrifugo-Fehler → `suppressPush=false` (Fallback: Push senden)
- **Keine Personal-Channels** nötig

### Recovery

- Centrifugo `force_recovery: true` → Clients erhalten verpasste Nachrichten bei Reconnect
- `history_size: 100`, `history_ttl: "600s"` → letzte 100 Nachrichten für 10 Minuten gespeichert

### History

- REST-Endpoint `GET /chat/conversations/{id}/messages?before=<seq>&limit=50` für historische Nachrichten
- Cursor-basierte Pagination (nach `seq`)

## Push-Unterdrückung (neue Semantik)

| Altes Verhalten | Neues Verhalten |
|---|---|
| `ChatPresence.activeUntil` prüft App-weit aktiv | `CentrifugoClient->presence()` prüft Chat geöffnet |
| Polling-basiert (5s Intervall) | WebSocket-basiert (sofortige Erkennung) |
| `ChatPresence`-Tabelle | Centrifugo Presence API |

**Begründung:** Conversation-Presence ist präziser – ein Nutzer kann die App im Hintergrund haben, aber den Chat nicht offen. In diesem Fall soll ein Push gesendet werden.

## Soft-Cut: `/chat/sync`

Der Endpoint `GET /chat/sync` bleibt vorerst registriert, antwortet aber leer:

```json
{
  "data": {
    "events": [],
    "conversations": [],
    "typing": {}
  },
  "meta": {
    "seq": 0,
    "hasMore": false
  }
}
```

**Grund:** Alte Clients laufen friedlich weiter bis Client-Update. Keine Presence-Touch.

## Graceful Degradation

- **CENTRIFUGO_ENABLED=false** → Alle Centrifugo-Calls sind No-ops (Local-Dev)
- **Fehler:** Niemals fatal – nur loggen, Chat funktioniert via REST weiter
- **Timeout:** `CENTRIFUGO_TIMEOUT` (~2s) für alle Server-API-Calls
- **Fallback bei Presence-Fehler:** Push senden (sicherer als nicht senden)

## Lesestatus (Read Receipts)

- Pro Teilnehmer wird `lastReadSeq` geführt. Der Sender sieht „gelesen", sobald `otherLastReadSeq >= msg.seq`.
- `POST /chat/conversations/{id}/read` setzt den eigenen Lesestand; das `seq` wird serverseitig auf das Maximum der Konversation begrenzt (Clamp).
- Read-Event wird via Centrifugo an andere Clients gepublished.

## Idempotenz

- `POST …/messages` mit `clientId`: **UNIQUE-Constraint** `(senderId, clientId)` + serverseitiger Lookup innerhalb derselben Konversation verhindern Duplikate bei Retry (auch unter parallelen Requests). Ein wiederverwendetes `clientId` in einer anderen Konversation wird als neuer Sendeversuch behandelt.

## Nachrichten-Aktionen

- **Bearbeiten**: Nur eigener Sender, innerhalb 10 Minuten. Setzt `editedAt` und published `message_edited`-Event via Centrifugo.
- **Löschen für alle**: Nur Sender. `deletedAt` gesetzt, `content`/`payload` geleert. Empfänger sieht Platzhalter. Published `message_deleted`-Event via Centrifugo.
- **Gelesen**: `lastReadSeq` pro Teilnehmer. Sender sieht "gelesen" wenn `empfänger.lastReadSeq >= msg.seq`. Published `read`-Event via Centrifugo.

## Notifications

- Typ `direct_message` in `NotificationService::CONTENT_TEMPLATES`
- **Body**: `"{Absender}: {Vorschau}"` (Vorschau auf 160 Zeichen gekürzt) — Push zeigt damit den Nachrichteninhalt
- **Bündelung**: Eine Notification pro Konversation (`dedupeKey = "chat:<conversationId>"`)
- Coalesced Upsert: Existierende ungelesene Notification wird aktualisiert statt neu anzulegen
- Denylist-Präferenz: `direct_message` mit `customData.userIds` (wie `story_post`)
- **Push-Unterdrückung**: Empfänger im Centrifugo-Channel → kein Push (aber In-App-Listeneintrag)

## Moderation

Chat-Nachrichten haben aktuell **keinen** `ModerationObjectType`. Die `VALID_OBJECT_TYPES` in `ModerationRequestService` enthalten:

```
user, forum_post, recipe, explore_place, recipe_review, forum_comment,
explore_comment, feedback_suggestion, feedback_comment, travel_trip,
travel_event, travel_accommodation, travel_ticket, subscription,
calendar_event, story
```

`chat_message` fehlt. Laut AGENTS.md muss jeder User-Content einen Report-Flag haben. **TODO:** `chat_message` zu `VALID_OBJECT_TYPES` hinzufügen und `resolveOwner` für Chat-Nachrichten implementieren. Der Report-Button für Chat kann ggf. auf die nächste Iteration verschoben werden.

## Cron

- `CleanupOldDirectMessagesTask`: Löscht Nachrichten älter als 90 Tage (gebatcht, LIMIT 1000)
- Räumt verwaiste Konversationen auf
- Intervall: 24 Stunden
- *Entfernt: ChatEvent, ChatPresence, ChatTyping Cleanup (Tabellen werden in Schritt 2C gelöscht)*

## Rate-Limits (per Nutzer)

- 20 Nachrichten/Minute pro Nutzer
- 30/Minute für Typing via Centrifugo Publish-Proxy
- Polling nicht drosseln (WebSocket ersetzt Polling)
- Antwort bei Überschreitung: `429 rate_limit_exceeded`

## Gültige `type`-Werte (aktuell)

- `text` (einziger implementierter Typ)
- `image`, `location` vorbereitet aber nicht implementiert

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
| `CENTRIFUGO_LOG_LEVEL` | Log-Level (error/warning) | error |
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
- **Typing:** Client-seitigem Publish mit `{ typing: bool }`
