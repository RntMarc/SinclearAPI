# Masterplan: Chat-Umstellung auf Centrifugo v6

**Status:** Schritt 2 abgeschlossen (Commits `2a–2f` auf `main`) – Schritt 3 (Deploy Centrifugo) abgeschlossen (Commits `efea506`, `89efdcd`); API-seitige Nachbesserungen (Presence-Normalisierung, `skip_history`, Fehler-Logging, Cleanup) umgesetzt  
**Branch:** `main`  
**Konkurrierender Branch `the-real-chat` (Matrix/Continuwuity):** Verworfen, kein Merge

---

## Entscheidungs-Log

| Thema | Entscheidung | Begründung |
|---|---|---|
| **Matrix-Branch** | Verwerfen | Centrifugo als architektonische Entscheidung fixiert |
| **Persistenz** | API-DB bleibt Source of Truth | 90-Tage-Retention, Unread-Counts, Moderation, Notifications funktionieren weiter; Centrifugo ist nur Transport/Recovery |
| **Sende-Flow** | REST + Server-API Publish | Idiomatisch (Centrifugo Design Doku), graceful degradation, Response enthält finale Message |
| **Push-Suppression** | Conversation-Presence | `presence(chat:<convId>)` prüft, ob Empfänger den Chat *gerade offen* hat (vs. bisher: App-weit aktiv) |
| **Notifications** | Unverändert (nur Chat) | Scope: Chat-Ersetzung; Realtime-Notifications optional später |
| **PHP-Client** | **Eigene schlanke Implementierung** | Guzzle ^7.9 bereits im Projekt; Projekt baut JWTs bereits selbst (TokenService RS256); keine neue Dependency; Shared Hosting bleibt ausreichend (nur kurzlebige HTTP-POSTs + Webhook-Annahme) |
| **`/chat/sync` Kompatibilität** | **Soft Cut** | Route bleibt, antwortet leer („keine Chats"), ohne Presence-Touch; alte Clients laufen friedlich weiter bis Client-Update |
| **Hosting Clients** | Direkt an `chat.sinclear.de` (WS/SSE) | PHP-Server hält **nie** lange Verbindungen |

---

## Ziel-Architektur

```
Sender-Client ──REST──► PHP-API (persist, validate)
                         │ ──publish──► Centrifugo (chat.sinclear.de) ──WS/SSE──► Empfänger-Clients
                         │ ──presence──► Centrifugo (Push-Suppression)
Centrifugo ──Subscribe-Proxy──► POST /api/v2/centrifugo/subscribe (PHP prüft ChatParticipant)
Client ──WS publish (typing)──► Centrifugo ──Publish-Proxy──► PHP (validiert) ──► Broadcast
```

### Channel-Modell

| Channel | Namespace | Optionen (Centrifugo `config.json`) |
|---|---|---|
| `chat:<conversationId>` | `chat` | `subscribe_proxy_enabled: true`, `publish_proxy_enabled: true`, `presence: true`, `join_leave: true`, `force_push_join_leave: true`, `force_recovery: true`, `force_positioning: true`, `history_size: 100`, `history_ttl: "600s"`, `channel_regex: "^[A-Za-z0-9_-]+$"`, `publication_data_format: "json"` |

*Keine Personal-Channels nötig (Entscheidung: Conversation-Presence).*

### Authentifizierung

- **Connection-JWT** (HS256): eigenes Secret `CENTRIFUGO_HMAC_SECRET`
- Claims: `sub` (userId als String), `exp` (15–30 min, TTL aus `CENTRIFUGO_TOKEN_TTL`), `iat`, `iss: "sinclear-api"`, `aud: "centrifugo"`
- Client refresht Token via SDK `getToken` Callback → `GET /chat/centrifugo/token`
- Server-seitig: `client.token.audience: "centrifugo"`, `client.token.issuer: "sinclear-api"` konfiguriert

---

## Schritt 2 – Detaillierte Umsetzungsaufgaben

### A. Neues Centrifugo-Modul (`src/Services/Centrifugo/`)

1. **`CentrifugoClient.php`** (Wrapper um Guzzle)
   - Methoden: `publish(channel, data)`, `unsubscribe(channel, userId)`, `presence(channel)`, `disconnect(userId)`
   - Header: `X-API-Key: <CENTRIFUGO_API_KEY>`
   - Timeouts: `CENTRIFUGO_TIMEOUT` (~2 s)
   - Fehler: **niemals fatal** – nur loggen (Chat funktioniert via REST weiter)
   - `CENTRIFUGO_ENABLED=false` → Noop (für Local-Dev/Graceful-Degradation)

2. **`CentrifugoTokenService.php`**
   - Handgebauter HS256-JWT (analog `TokenService::generateToken`, aber `hash_hmac('sha256')`)
   - `generateConnectionToken(string $userId): string` – liest Secret/TTL/Issuer/Audience aus Settings
   - `parseToken(string $token): ?object` – für Tests/Validierung (liefertstdClass-Payload oder null)

3. **`ChatEventPublisher.php`**
   - Baut Event-Payloads und published via `CentrifugoClient`
   - Events:
     - `message_created` – nach `sendMessage` (inkl. `formatMessage`-Shape + `DirectMessage.seq`)
     - `message_edited` – nach `editMessage`
     - `message_deleted` – nach `deleteMessage`
     - `read` – nach `markRead` (mit `seq`/`lastReadSeq`/`userId`)
   - Channel: `chat:<conversationId>`
   - Graceful: Fehler geloggt, nicht geworfen

4. **Config & DI**
   - `settings.php`: neue Sektion `centrifugo` (alle Env-Vars)
   - `.env.example`: `CENTRIFUGO_API_URL`, `CENTRIFUGO_API_KEY`, `CENTRIFUGO_HMAC_SECRET`, `CENTRIFUGO_TOKEN_TTL`, `CENTRIFUGO_WS_URL`, `CENTRIFUGO_TIMEOUT`, `CENTRIFUGO_ENABLED`
   - DI-Wiring in `config/dependencies.php`

---

### B. API-Endpoints

5. **NEU: `GET /chat/centrifugo/token`** (JWT-auth)
   - Response: `{ token, url, expiresAt }`
   - `url` = `CENTRIFUGO_WS_URL` (z. B. `wss://chat.sinclear.de/connection/websocket`)
   - Dient auch als SDK-Refresh-Endpoint (`getToken` Callback)

6. **NEU interne Proxy-Endpoints** (`/centrifugo/*`)
   - **Kein JWT** – gesichert via `CentrifugoProxyMiddleware`:
     - Shared Secret Header `X-Centrifugo-Proxy-Key` (Centrifugo sendet via `http.static_headers`)
     - `RequireHttpsMiddleware`
     - Orientierung an `LocationSharingIngressController`-Muster
   - `POST /centrifugo/subscribe`
     - Request: Centrifugo `SubscribeRequest` (user, channel)
     - Prüfung: `ChatParticipantRepository::isParticipant(userId, conversationId)`
     - Response: `{result:{}}` oder 403
   - `POST /centrifugo/publish` (nur für Typing)
     - Request: Centrifugo `PublishRequest` (user, channel, data)
     - Prüfung: Participant + Rate-Limit (30/min analog `TYPING_RATE_LIMIT`)
     - Response: `{result:{data: <sanitized-typing-payload>}}`

7. **Soft Cut: `ChatController::sync`**
   - Bleibt registriert, antwortet: `{data: {events: [], conversations: [], typing: {}}, maxSeq: 0}` (kein Presence-Touch)
   - Dokumentation: deprecated, leere Antwort

8. **Entfernt: `POST /chat/conversations/{id}/typing`**
   - Response: **410 Gone** (Typing läuft via Publish-Proxy)

9. **`DirectMessageService` Anpassungen**
   - Entfernen: `sync()`, `setTyping()`, Dependencies `eventRepo`/`presenceRepo`/`typingRepo`
   - Hinzufügen: `ChatEventPublisher` Dependency
   - `sendMessage`/`editMessage`/`deleteMessage`/`markRead` → nach Persistenz `publisher->publish*()`
   - `notifyParticipants`: **1×** `CentrifugoClient->presence("chat:<convId>")` pro Sendung; Empfänger im Channel → `suppressPush=true`; bei Centrifugo-Fehler → `suppressPush=false` (sicherer Fallback: pushen)

10. **`TravelChatService::reconcileParticipants`**
    - Bei Entfernen von Teilnehmern: `CentrifugoClient->unsubscribe("chat:<convId>", userId)`
    - (Centrifugo hat keinen Unsubscribe-Hook – API muss aktiv abmelden)

11. **Cron: `CleanupOldDirectMessagesTask`**
    - Entfernen: `ChatEvent`/`ChatPresence`/`ChatTyping`-Cleanup
    - Behalten: `DirectMessage` Retention (90 Tage) + Orphan-Conversations
    - `docs/CRON.md` aktualisieren (Zeile 31, 55–59)

---

### C. Datenbank-Migration

Datenbanktabellen dürfen verändert werden. Datenverlust ist akzeptiert und stellt kein Problem dar.

12. **`database/migrations/2026XXXXXX_drop_chat_realtime_tables.sql`** (dated Format)
    ```sql
    DROP TABLE IF EXISTS `ChatEvent`, `ChatPresence`, `ChatTyping`;
    -- Legacy aus Live-DB (sibed-nodata.sql, nicht mehr im Code genutzt):
    DROP TABLE IF EXISTS `ChatMessages`, `ChatRooms`, `ChatRoomMembers`, `ChatReadReceipt`, `DirectChat`, `UserPresence`, `SseEvent`;
    DROP EVENT IF EXISTS `cleanup_messages`;
    ```
    - **Keine neuen Tabellen** nötig
    - Unread-Counts laufen über `DirectMessage.seq` + `ChatParticipant.lastReadSeq` (verifiziert: `markRead` nutzt `messageRepo->getMaxSeq`, `listForUser` zählt über `DirectMessage.seq > lastReadSeq`)

13. **Dateien löschen:**
    - `src/Repository/ChatEventRepository.php`
    - `src/Repository/ChatPresenceRepository.php`
    - `src/Repository/ChatTypingRepository.php`
    - DI-Einträge in `config/dependencies.php` entfernen

---

### D. Dokumentation

14. **Rewrite `docs/chat/readme.md`** (vollständig neu)
    - Architektur-Überblick (Diagramm)
    - Channel-/Namespace-Modell
    - Auth-Flow: Token-Endpoint → Connect → Subscribe-Proxy
    - Message-Flow: REST → Persist → Publish
    - Typing via Publish-Proxy
    - Presence / Recovery / History
    - Push-Suppression (neue Semantik)
    - Soft-Cut-Hinweis (`/chat/sync`)
    - Graceful Degradation
    - Env-Vars + Server-Config-Referenz
    - **Deep-Links zu Centrifugo-Docs:**
      - Channels/Namespaces: `https://centrifugal.dev/docs/server/channels`
      - Channel Token Auth: `https://centrifugal.dev/docs/server/channel_token_auth`
      - Proxy: `https://centrifugal.dev/docs/server/proxy`
      - Server API: `https://centrifugal.dev/docs/server/server_api`
      - Authentication: `https://centrifugal.dev/docs/server/authentication`
      - Design/Idiomatic Usage: `https://centrifugal.dev/docs/getting-started/design`
      - Client API/SDK: `https://centrifugal.dev/docs/transports/client_api`
    - Flutter-SDK Referenz: `centrifuge` 0.20.1 (pub.dev)

15. **NEU `docs/chat/centrifugo.md`** (Server-Deploy für Schritt 3)
    - Domain: `chat.sinclear.de`
    - `config.json` Beispiel mit exakten Namespace-Optionen (siehe Tabelle oben)
    - `http_api.key`, `client.token.hmac_secret_key`, `client.token.audience`, `client.token.issuer`
    - Proxy-Endpoints: `subscribe` → `https://api.sinclear.de/api/v2/centrifugo/subscribe`, `publish` → `.../publish`
    - Docker: `centrifugo/centrifugo:v6`
    - Reverse Proxy (Caddy/nginx): TLS, WebSocket-Upgrade, CORS `allowed_origins`
    - Engine: Memory (Single-Node, ~10 Nutzer) – Redis optional für HA

16. **Delete `docs/chat/plan.md`** (veralteter Entscheidungs-Log „gegen Realtime-Daemon")

17. **Update `docs/notifications/types.md` & `readme.md`**
    - `direct_message`: Trigger unverändert; Suppression-Semantik: „Chat geöffnet" statt „App aktiv"; `custom` Denylist (`userIds`) unverändert

18. **`MCP.md` Check**
    - MCP-Server scannt automatisch alle `.md` in `docs/` → neue Topics erscheinen automatisch im enum (keine manuelle Pflege nötig, nur prüfen dass `MCP.md` keine Kern-Themen-Beschränkung enthält)

---

### E. OpenAPI & Security

19. **`openapi.yaml` Anpassungen**
    - **Tag `[Chat]`:**
      - `GET /chat/centrifugo/token` neu (Schema `CentrifugoTokenResponse`)
      - `GET /chat/sync`: deprecated markieren, Response-Beschreibung „Leere Antwort – Endpoint wird entfernt"
      - `POST /chat/conversations/{id}/typing`: 410 Gone dokumentieren oder entfernen
    - **Neue interne Endpoints** (Security: `X-Centrifugo-Proxy-Key` Header):
      - `POST /centrifugo/subscribe` (Schema `CentrifugoSubscribeRequest/Response`)
      - `POST /centrifugo/publish` (Schema `CentrifugoPublishRequest/Response`)
    - **Schemas entfernen:** `ChatSyncResponse`, `ChatEvent`, `ChatTypingRequest`
    - **Schemas neu:** `CentrifugoTokenResponse`, `CentrifugoSubscribeRequest`, `CentrifugoSubscribeResponse`, `CentrifugoPublishRequest`, `CentrifugoPublishResponse`

20. **`.htaccess` Check**
    - Neue Routen laufen über `public/index.php` → kein Eintrag nötig
    - `.env` / `.log` / `.sql` / `*.md` bleiben geschützt (bestehende Regeln Zeile 43, 56–66)

21. **Admin Dashboard Konsistenz**
    - Travel-Chat-Panels (`templates/admin/trip_detail.php`, `event_detail.php`) bleiben funktional
    - Keine Änderungen nötig (Endpoints `/admin/travel/trips/{id}/chat` etc. unverändert)

---

### F. Tests (jetzt schreiben, laufen in Schritt 5)

22. **Unit Tests (lokal, ohne DB)**
    - `CentrifugoTokenServiceTest`: JWT-Struktur, Claims, HS256-Signatur verifizierbar
    - `ChatEventPublisherTest`: Payload-Shapes für alle 4 Event-Typen
    - `CentrifugoClientTest`: mit Guzzle `HandlerStack` Mock – publish/presence/unsubscribe, Fehler-Toleranz
    - `CentrifugoProxyMiddlewareTest`: Key-Check Logik

23. **DB-abhängige Integrationstests (nur Server via `update.sh`)**
    - Subscribe-Proxy: Permission-Check (`isParticipant` true/false)
    - `sendMessage` → Publish-Wiring (mit `CENTRIFUGO_ENABLED=false` als Noop)

24. **Lokale statische Prüfungen**
    - `php -l` auf allen neuen/geänderten Dateien
    - `vendor/bin/phpstan` (Level laut `phpstan.neon`)
    - `vendor/bin/phpunit --filter '^((?!Database).)*$'` (nur Tests ohne DB)

---

## Sequenzierung innerhalb Schritt 2

| Phase | Aufgaben |
|---|---|
| **A. Infrastructure** | Settings, Env, CentrifugoClient, TokenService, ChatEventPublisher, DI |
| **B. Proxy Layer** | Middleware, `/centrifugo/subscribe`, `/centrifugo/publish` |
| **C. Core Chat Rewiring** | DirectMessageService, TravelChatService, ChatController (Token-Endpoint, Sync-Stub, Typing-Entfernung) |
| **D. Cron & Cleanup** | CleanupOldDirectMessagesTask, Migration |
| **E. Doku & Specs** | readme.md, centrifugo.md, plan.md löschen, notifications, openapi.yaml, CRON.md |
| **F. Tests & Verify** | Unit Tests, phpstan, php -l, AGENTS.md Consistency Checks |

---

## Schritt 3 – Deploy Centrifugo (in Durchführung)

### Infrastruktur
- **Domain:** `chat.sinclear.de` (TLS via Traefik auf Homeserver)
- **WebSocket:** `wss://chat.sinclear.de/connection/websocket`
- **SSE Fallback:** `https://chat.sinclear.de/connection/uni_sse` / `/connection/http_stream`
- **Engine:** Memory (Single-Node, ~10 Nutzer, kein Redis nötig)

### Traefik (Homeserver)
```yaml
# docker-compose Labels für centrifugo:8000
- "traefik.enable=true"
- "traefik.http.routers.centrifugo.rule=Host(`chat.sinclear.de`)"
- "traefik.http.routers.centrifugo.entrypoints=websecure"
- "traefik.http.routers.centrifugo.tls.certresolver=letsencrypt"
- "traefik.http.services.centrifugo.loadbalancer.server.port=8000"
```
Traefik handhabt WebSocket-Upgrade automatisch. Timeouts ggf. erhöhen.

### Secrets
Drei unabhängige Secrets per `openssl rand -hex 32` erzeugen:
- `CENTRIFUGO_API_KEY` → `.env` (API-Server) + `config.json` (`api_key` + `http_api.key`)
- `CENTRIFUGO_HMAC_SECRET` → `.env` (API-Server) + `config.json` (`client.token_hmac_secret_key`)
- `CENTRIFUGO_PROXY_KEY` → `.env` (API-Server) + `config.json` (`http_api.static_headers.X-Centrifugo-Proxy-Key`)

**Wichtig:** Alle drei Secrets müssen unterschiedlich sein (unterschiedliche Vertrauensrichtungen, getrennt rotierbar).

### `allowed_origins`
Produktiv (direkt restriktiv, kein `*`-Zwischenschritt):
```json
"allowed_origins": ["https://sinclear.de", "https://app.sinclear.de", "https://*.sinclear.de"]
```
- `https://sinclear.de` = PWA (braucht Origin-Header)
- Android-App (`de.sinclear.beyond`) braucht keinen Eintrag (kein browserbasierter Origin)

### `config.json` – exakt wie in `docs/chat/centrifugo.md`
**Vor Deploy prüfen:** Bestehende `config.json` auf Centrifugo-Host verwerfen und exakt mit Doku-Version überschreiben (Namespace `chat` mit allen Optionen, `client.token_issuer/audience`, `proxy.subscribe/publish_endpoint`, `engine: memory`).

### Deploy-Sequenz
1. Secrets generieren → `.env` (API) + `config.json` (Centrifugo)
2. `config.json` auf Centrifugo-Host deployen (Doku-Version)
3. Traefik-Labels/Router konfigurieren → `chat.sinclear.de` → `:8000`
4. `CENTRIFUGO_WS_URL` + `CENTRIFUGO_API_URL` in `.env` setzen
5. Zuerst `CENTRIFUGO_ENABLED=false` lassen
6. Tests durchführen (s. Testprotokoll)
7. `CENTRIFUGO_ENABLED=true` setzen + `update.sh` Deploy

### Testprotokoll
1. `GET https://chat.sinclear.de/api/info` → 200 (TLS + Routing ok)
2. `GET /api/v2/chat/centrifugo/token` (JWT) → `{token, url, expiresAt}`
3. `POST /api/v2/centrifugo/subscribe` ohne/avec falschem Key → 403; mit Key + Teilnehmer → `{result:{}}`
4. PWA (`https://sinclear.de`): WS-Connect + `chat:<id>`-Subscribe → REST-`POST …/messages` kommt als `message_created` an
5. Android-Debug-App: gleicher Flow ohne Origin-Probleme
6. Negativ: Centrifugo gestoppt → REST-Chat funktioniert weiter (Graceful Degradation)

---

## Offene Punkte / Nachbereitung (nach Schritt 3)

- [ ] Client-Agent implementiert Flutter-Integration (centrifuge-dart 0.20.1, Token-Refresh via `getToken`, Subscription auf `chat:<id>`, Typing via Client-seitigem Publish)
- [ ] End-to-End Test: Message senden → REST Response + Centrifugo Event empfangen
- [ ] Load-Test: Reconnect-Sturm (Presence/Recovery) simulieren
- [ ] Monitoring: Centrifugo `/api/info` + Metriken (optional PRO Analytics)

---

*Dieser Masterplan ist die verbindliche Entscheidungsgrundlage. Änderungen nur nach Rücksprache.*