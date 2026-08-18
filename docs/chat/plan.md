# Chat / Direktnachrichten – Implementierungsplan

> Status: **Plan** (noch nicht implementiert). Dieses Dokument beschreibt Ziel, Architektur und Umsetzungsschritte. Es wird bei der Implementierung als Grundlage verwendet und ggf. fortgeschrieben.

## 1. Ziel & Umfang

- Jeder Nutzer kann jedem anderen Nutzer eine Direktnachricht (1:1-Chat) senden.
- Vorerst reiner Text, keine Dateianhänge. **Bilder und Standort kommen später garantiert** — das Schema wird dafür vorbereitet.
- Nachrichten werden nach **90 Tagen automatisch gelöscht**.
- Kein E2EE (bewusst verworfen). Server kennt Klartext, Push darf Inhalte zeigen.
- Gruppenchats später — Schema wird von Anfang an gruppentauglich gebaut.
- Kein Redis, kein Daemon, nur **PHP-FPM + Apache + MySQL** (Managed Hosting).

## 2. Entscheidungs-Log (konsolidiert)

| Thema | Entscheidung |
|---|---|
| Infrastruktur | Managed Hosting, nur PHP-FPM/Apache/MySQL. Kein Redis, keine langlaufenden Prozesse. Raspberry Pi (armv7) als Notlösung vorhanden, wird **nicht** benötigt. |
| Skalierung | ~10 aktive Nutzer, ein Server, kein LB. |
| Clients | Flutter Android, Flutter Web, Flutter Linux. Kein iOS. Client richtet sich vollständig nach der API. |
| Realtime aktiv | **Short Polling** (adaptiv) statt Long Polling — PHP-FPM-Worker-Pool ist knapp, Long Polling würde Worker zu lange binden. |
| Realtime Hintergrund | Web Push (bestehend, `minishlink/web-push`) + UnifiedPush. Push-Inhalt anzeigen. |
| E2EE | **Weglassen** (spätere Erweiterung wird durch `payload`/`type`-Design offen gehalten, aber nicht mitgebaut). |
| Lesebestätigung | **Nur „gelesen"**, kein „zugestellt". |
| Nachrichten-Aktionen | **Löschen für alle** (Empfänger sieht Platzhalter „Nachricht gelöscht") + **Bearbeiten mit Zeitfenster** (10 Min, konfigurierbar). |
| Blockliste | Keine. |
| Nachrichtenlänge | 2000 Zeichen. |
| Rate-Limit | 20 Nachrichten/Minute pro Nutzer. |
| Notifications | Chat-Meldungen **gebündelt pro Konversation** in der Notification-Liste (nicht pro Nachricht). |
| URL-Previews | **Client-seitig** wie im Forum (Server speichert nur Text; Client erkennt URLs und lädt Metadaten). Kein Server-Fetch → kein SSRF. |
| Aufbewahrung | Global 90 Tage. |
| Admin-Panel | Kein Chat-Panel. |
| Gruppen | Später; `Conversation` + `ChatParticipant` von Anfang an. |

## 3. Architektur-Überblick

```
Client (Flutter)
   │
   ├─ REST CRUD ──────────────►  POST/GET/PATCH/DELETE /chat/*
   ├─ Aktiver Chat ───────────►  GET /chat/sync  (Short Polling, adaptiv 2–3 s)
   ├─ Hintergrund ────────────►  Web Push (PushSubscription, bestehend) → Client synct einmal
   └─ Tippindikator ──────────►  POST /chat/conversations/{id}/typing  (debounced)
```

- Die **Wahrheit** liegt in den Nachrichten-Tabellen; jede Realtime-Quelle ist nur ein „Wecker". Sync über monotone `seq`-Cursor garantiert, dass bei Verbindungsabbrüchen nichts verloren geht.
- **Pub/Sub-Ersatz ohne Redis**: „publish" = neue Zeile in `DirectMessage`; „subscribe" = `GET /chat/sync?after=<seq>` pollt `seq > cursor`. Für ~10 Nutzer ausreichend.
- **Push-Unterdrückung bei aktivem Chat**: Sender prüft `ChatPresence.activeUntil` des Empfängers; ist der Empfänger aktiv (pollt), wird kein Web Push gesendet, da er die Nachricht über den nächsten Sync erhält.

### Latenz-Ziel
Auf diesem Hosting ist **~1–2 s** Zustelllatenz im aktiven Chat realistisch (Short-Poll-Intervall). Für echtes Echtzeit-Feeling (<100 ms) wäre ein WebSocket/SSE-Daemon oder ein Redis-Relay nötig — als spätere Option notiert (siehe §15), aber nicht Teil dieses Plans.

## 4. Datenmodell (Entwurf)

Konventionen: `id` = `Uuid::uuid7()` als `varchar(191)` (zeitlich sortiert, konsistent zum Codebase). `seq` = globaler monotoner `BIGINT`-Cursor für Sync/Pagination (unabhängig von der UUID, da UUIDv7 bei gleicher Millisekunde nicht strikt sortierbar ist).

### 4.1 `ChatConversation`
| Spalte | Typ | Bemerkung |
|---|---|---|
| `id` | varchar(191) PK | uuid7 |
| `type` | enum('direct','group') | default 'direct'; Gruppe später |
| `name` | varchar(255) NULL | für Gruppen später |
| `createdAt` | datetime(3) | |
| `updatedAt` | datetime(3) | letzte Aktivität (denormalisiert) |

### 4.2 `ChatParticipant`
| Spalte | Typ | Bemerkung |
|---|---|---|
| `conversationId` | varchar(191) FK | |
| `userId` | varchar(191) FK | |
| `joinedAt` | datetime(3) | |
| `lastReadSeq` | bigint unsigned | default 0; Lesestand pro Teilnehmer |
| `lastSeenAt` | datetime(3) NULL | „zuletzt gesehen" |
| PK | (conversationId, userId) | |

- Unread = Anzahl `DirectMessage` mit `conversationId = X AND seq > myLastReadSeq AND senderId != me AND deletedAt IS NULL`.
- „Gelesen" einer Nachricht = `myLastReadSeq >= msg.seq`.

### 4.3 `DirectMessage`
| Spalte | Typ | Bemerkung |
|---|---|---|
| `id` | varchar(191) PK | uuid7 (Ressourcen-ID) |
| `seq` | bigint unsigned UNIQUE AUTO_INCREMENT | globaler Sync-Cursor |
| `conversationId` | varchar(191) FK | |
| `senderId` | varchar(191) FK | |
| `type` | enum('text','image','location',…) | default 'text' |
| `content` | text | Textkörper (bei `text`) |
| `payload` | json NULL | strukturierte Daten: `{lat,lng}` bei `location`, `{fileId}` bei `image` |
| `clientId` | varchar(64) NULL | Idempotenz (Dedup bei Retry) |
| `editedAt` | datetime(3) NULL | gesetzt bei Bearbeitung |
| `deletedAt` | datetime(3) NULL | gesetzt beim Löschen (für alle); `content`/`payload` werden geleert |
| `deletedBy` | varchar(191) NULL | userId, die gelöscht hat |
| `createdAt` | datetime(3) | |

Indexe: `(conversationId, seq)`, `seq` (unique), `senderId`, `(senderId, clientId)` (Dedup).

### 4.4 `ChatPresence` (Push-Unterdrückung)
| Spalte | Typ | Bemerkung |
|---|---|---|
| `userId` | varchar(191) PK | |
| `activeUntil` | datetime(3) | bei jedem Sync auf `now + 5 s` gesetzt |

### 4.5 `ChatTyping` (Tippindikator, ephemer)
| Spalte | Typ | Bemerkung |
|---|---|---|
| `conversationId` | varchar(191) | |
| `userId` | varchar(191) | |
| `expiresAt` | datetime(3) | `now + 5 s`; Tippzustand gilt bis Ablauf |
| PK | (conversationId, userId) | |

`ChatTyping`/`ChatPresence` werden vom 90-Tage-Cron (bzw. einem eigenen kurzen Cleanup) bereinigt, bleiben aber klein.

## 5. REST-API (Entwurf)

Alle Routen unter `AuthenticationMiddleware`. Auth-Zugriff über `DirectMessagePolicy` (nur Teilnehmer der Konversation).

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/chat/conversations` | Liste (letzte Nachricht, Unread, `lastSeenAt`, `updatedAt`) |
| POST | `/chat/conversations` | 1:1-Konversation öffnen (Body `{userId}`), idempotent (get-or-create) |
| GET | `/chat/conversations/{id}` | Konversation + Teilnehmer |
| GET | `/chat/conversations/{id}/messages?before=<seq>&limit=50` | History (aufsteigend, Cursor `before`) |
| POST | `/chat/conversations/{id}/messages` | Senden (Body `{clientId, type, content, payload?}`) |
| PATCH | `/chat/messages/{id}` | Bearbeiten (nur eigener, innerhalb Zeitfenster) |
| DELETE | `/chat/messages/{id}` | Löschen (für alle; `content`/`payload` leeren, Empfänger sieht Platzhalter „Nachricht gelöscht") |
| POST | `/chat/conversations/{id}/read` | Lesestand setzen (Body `{seq}`) |
| POST | `/chat/conversations/{id}/typing` | Tippindikator (Body `{typing: bool}`) |
| **GET** | **`/chat/sync?after=<seq>&limit=`** | **Optimierte Route:** alle neuen Nachrichten + Lesestände + Tippzustände über alle Konversationen in einem Aufruf |

### Pagination / Cursor
- History: `before=<seq>` (absteigend laden, stabil bei neuen Nachrichten).
- Sync: `after=<seq>` (aufsteigend, nur Neues). Client merkt sich den höchsten gesehenen `seq`.

### Idempotenz
- `POST …/messages` mit `clientId`: bei Netzwerk-Retry wird dieselbe Nachricht nicht doppelt gespeichert (Unique-Lookup `(senderId, clientId)`). Ohne `clientId` optional (Client sollte sie aber senden).

## 6. Realtime & Presence

### 6.1 Aktiver Chat (Short Polling)
- Client pollt `GET /chat/sync?after=<seq>` mit **adaptivem Intervall**:
  - Konversation offen / aktiv: **2–3 s**.
  - Idle: Backoff bis **30 s**, danach stoppen und auf Web Push warten.
- Kein Long Polling (Worker-Pool zu knapp). Optional als Tuning-Knopf später: sehr kurzes Long Poll (≤5 s), falls der Pool es zulässt.

### 6.2 Hintergrund (Web Push)
- Neue Nachricht → `NotificationService` erzeugt gebündelte Notification (siehe §8) und sendet Web Push, **sofern** `ChatPresence.activeUntil` des Empfängers in der Vergangenheit liegt.
- Client empfängt Push → öffnet App → ein Sync holt alle offenen Nachrichten.

### 6.3 Presence
- `lastSeenAt` wird bei jedem Sync/Aufruf aktualisiert; angezeigt nur innerhalb bestehender Konversationen (Privatsphäre).
- `activeUntil` = `now + 5 s` bei jedem Sync; steuert die Push-Unterdrückung.
- Tippindikator: Client sendet `POST …/typing` debounced (~alle 3 s während des Tippens); Zustand erscheint in der Sync-Antwort als `typingUserIds` (läuft nach 5 s ab).

## 7. Nachrichten-Aktionen

- **Bearbeiten**: `PATCH /chat/messages/{id}`, nur eigener Sender, nur innerhalb **10 Min** (Konstante). Setzt `editedAt`. Client zeigt „bearbeitet"-Markierung.
- **Löschen (für alle)**: `DELETE /chat/messages/{id}` → setzt `deletedAt`, leert `content`/`payload`. Empfänger sieht Platzhalter „Nachricht gelöscht". Nur der Sender darf löschen.
- **„Gelesen"**: `POST …/read` setzt `lastReadSeq`. Der Sender sieht „gelesen", sobald `empfänger.lastReadSeq >= msg.seq`.
- Kein „zugestellt" (verworfen), kein Blockieren.

## 8. Notification-Integration (gebündelt)

- Neuer Typ **`direct_message`** in:
  - `NotificationService::CONTENT_TEMPLATES` (Titel/Text, z.B. „Neue Nachricht von {name}"),
  - `NotificationPreferenceService::KNOWN_TYPES` + `CUSTOMIZABLE_TYPES` (custom = Denylist `userIds`, Vorbild `story_post`).
- **Bündelung**: eine Notification **pro Konversation** statt pro Nachricht. Dazu Erweiterung von `NotificationService` um ein **Coalesced-Upsert**:
  - `dedupeKey = "chat:<conversationId>"` (neue Spalte `dedupeKey varchar(191) NULL` + Index auf `Notification`).
  - Existiert eine ungelesene Notification mit diesem Key → Titel/Text/`unreadCount` (in `data`) aktualisieren, statt neu einzufügen.
  - `body`: „3 neue Nachrichten von {name}".
- Web Push enthält den Inhalt der letzten Nachricht (kein E2EE → erlaubt).

## 9. 90-Tage-Aufbewahrung (Cleanup)

- Neuer Cron-Task `CleanupOldDirectMessagesTask` (Vorbild `CleanupOldLocationSharingTask`):
  - Gebatchtes `DELETE FROM DirectMessage WHERE createdAt < NOW() - INTERVAL 90 DAY LIMIT 1000` in Schleife (vermeidet lange Locks/Replikations-Lag).
  - Anschließend verwaiste `ChatConversation`/`ChatParticipant` ohne Nachrichten ggf. aufräumen (optional, vorsichtig).
- Registrierung in `bin/cron.php`.
- **Alternative (später bei Skalierung):** MySQL-Partitionierung (`RANGE` auf `createdAt`, monatlich) → `DROP PARTITION` statt `DELETE`. Nicht nötig bei aktueller Größe.

## 10. Später: Anhänge (Bilder, Standort)

Vorbereitet, aber nicht implementiert:
- `type` erlaubt bereits `image` / `location`.
- `payload` trägt strukturierte Daten: `location` → `{lat,lng}`; `image` → `{fileId, width, height, …}`.
- Bilder: Wiederverwendung des vorhandenen `ImageService` (wie Review-Fotos); Upload-Endpunkt als separater Schritt (z.B. `POST /chat/messages/{id}/attachment` oder Upload-vor-Senden).
- `DELETE`-Cron muss später auch die zugehörigen Dateien/Blobs entfernen (bei `image`).

## 11. Später: Gruppenchats

- `ChatConversation.type = 'group'`, `name` gesetzt; mehrere `ChatParticipant`.
- `lastReadSeq`/Unread ist bereits gruppentauglich (pro Teilnehmer).
- Zusätzlich später: `role` (admin/member), `leftAt`, Nachrichten-Löschregeln, Gruppeneinladungen.

## 12. Sicherheit, Policies, Rate-Limits

- `DirectMessagePolicy`: Nur Teilnehmer dürfen lesen/schreiben; `PATCH`/`DELETE` nur eigener Sender (`DELETE` ohne Zeitfenster).
- Rate-Limit: **20 Nachrichten/Minute** pro Nutzer über vorhandenen `RateLimiter` (bzw. eigenes Middleware analog `LoginThrottleMiddleware`).
- Separate Limits für `…/typing` (z.B. 30/Min) und `…/sync` (Polling nicht drosseln, aber ggf. Mindest-Intervall serverseitig nicht erzwungen).
- Validierung: `content` ≤ 2000 Zeichen, nicht leer, `type` ∈ Enum, `payload`-Struktur je Typ.
- Input-Escaping: `content` als Text (kein HTML-Rendering serverseitig); Clients rendern als Plain Text.
- `DELETE` leert `content`/`payload` physisch (Datensparsamkeit; Platzhalter wird aus `deletedAt` abgeleitet).

## 13. Doku-Pflichten (AGENTS.md) bei Implementierung

Bei der Umsetzung sind folgende Dateien **zwingend** mitzupflegen:

1. `openapi.yaml` — alle neuen Endpunkte, DTOs, Schemas.
2. `docs/chat/readme.md` — Endpunkt-/Flow-Doku (dieses `plan.md` bleibt Plan-Doku).
3. `docs/CRON.md` — neuer Task `CleanupOldDirectMessagesTask`.
4. `docs/notifications/types.md` — neuer Typ `direct_message` (Trigger, Relations, Texte).
5. `docs/notifications/readme.md` — falls Präferenz/`custom`-Semantik betroffen.
6. `.htaccess` — Sicherheit unverändert prüfen.
7. Admin-Dashboard — bewusst **kein** Chat-Panel (Entscheidung §2), daher keine Dashboard-Änderung nötig.
8. MCP: `docs/chat/plan.md`/`readme.md` werden automatisch als Topics `chat/plan` bzw. `chat/readme` gescannt (kein `enum`-Eingriff; `MCP.md` konsultieren).

## 14. Offene Punkte / Risiken

| # | Punkt | Status |
|---|---|---|
| 1 | PHP-FPM-Worker-Pool-Größe unbekannt | Short Polling gewählt → kein Dauer-Worker-Binding. Bei Bedarf minimale Anpassung des Poll-Intervalls. |
| 2 | Latenz ~1–2 s im aktiven Chat | Akzeptiert; Beschleunigung später via Daemon/Redis (nicht jetzt). |
| 3 | UUIDv7-Sortierung bei gleicher Millisekunde | Durch globalen `seq`-Cursor entschärft. |
| 4 | `Notification.dedupeKey`-Erweiterung nötig | Umsetzung bei Implementierung, Migration ergänzen. |
| 5 | Bearbeiten-Zeitfenster (10 Min) | Konstante, ggf. anpassbar. |
| 6 | Verwaiste Konversationen nach Cleanup | Optionales Aufräumen, niedrige Priorität. |
| 7 | Clients | Kein iOS; Flutter Android/Web/Linux müssen Sync/Push unterstützen (Web Push in Flutter Web via JS-Interop). |

## 15. Implementierungs-Reihenfolge (Vorschlag)

1. Migrationen `create_chat_tables.sql` (+ `Notification.dedupeKey`).
2. Repositories (`ChatConversationRepository`, `ChatParticipantRepository`, `DirectMessageRepository`, `ChatPresenceRepository`).
3. `DirectMessageService` (senden, bearbeiten, löschen, lesen, Sync, Tippen, Presence) + `DirectMessagePolicy`.
4. Controller + Routen in `config/routes.php`.
5. Notification-Integration (`direct_message` + Coalesced-Upsert in `NotificationService`).
6. `CleanupOldDirectMessagesTask` + Registrierung in `bin/cron.php`.
7. `openapi.yaml` + alle Doku-Dateien (§13).
8. Lokale statische Prüfung: `php -l`, `vendor/bin/phpstan`, Unit-Tests ohne DB. DB-Integrationstests laufen beim Deploy auf dem Server.

---

## 16. Geklärt (Konsolidierung offener Detailfragen)

- Bearbeiten-Zeitfenster: **10 Minuten**.
- Löschen: **für alle**, Empfänger sieht Platzhalter „Nachricht gelöscht" (Sender sieht nichts).
- `GET /chat/sync` liefert `typingUserIds` **immer** mit (bei aktivem Chat wird ohnehin gepollt).
