# Chat / Direktnachrichten

1:1-Direktnachrichten und Gruppenchats zwischen Nutzern. Text-only (Bilder/Standort vorbereitet, aber nicht implementiert).

## Architektur

- **Wahrheit**: `DirectMessage`-Tabelle mit globalem monotonen `seq`-Cursor
- **Sync über Events**: `ChatEvent`-Tabelle protokolliert jede Nachrichten-Änderung (`message_created`, `message_edited`, `message_deleted`) mit eigenem monotonen `seq`. So werden auch Bearbeitungen und Löschungen bereits zugestellter Nachrichten in Echtzeit propagiert.
- **Realtime**: Short Polling via `GET /chat/sync?after=<eventSeq>` (adaptiv 2–3 s aktiv, 30 s idle)
- **Push**: Web Push + UnifiedPush, unterdrückt wenn Empfänger aktiv pollt (`ChatPresence`)
- **Kein E2EE**: Server kennt Klartext, Push darf Inhalte zeigen
- **Aufbewahrung**: 90 Tage (Nachrichten + Events), automatisch via Cron

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `ChatConversation` | Konversation (type: direct/group) |
| `ChatParticipant` | Teilnehmer + `lastReadSeq` + `lastSeenAt` |
| `DirectMessage` | Nachrichten mit `seq` (globaler Sync-Cursor) |
| `ChatEvent` | Nachrichten-Events (`message_created`/`message_edited`/`message_deleted`) mit eigenem `seq` |
| `ChatPresence` | Push-Unterdrückung (`activeUntil`) — **nicht** für Online-Anzeige gedacht |
| `ChatTyping` | Tippindikator (ephemer, läuft nach 5 s ab) |
| `TravelChat` | Verknüpfung von Gruppenchat mit Reise oder Event |

### Gruppenchat (type: group)

Gruppenchats werden aktuell nur admin-seitig für Reisen und Events erstellt. Manuelle User-Gruppenchats sind geplant, aber nicht implementiert.

- `ChatConversation.type = 'group'` + `ChatConversation.name = Reise-/Event-Name`
- `ChatParticipant`-Einträge werden aus `TravelRelation`/`EventRelation` gespiegelt
- `TravelChat`-Tabelle speichert die Zuordnung (Reise oder Event)
- Bei Hinzufügen/Entfernen von Teilnehmern wird `ChatParticipant` automatisch synchronisiert
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

### ChatEvent

```json
{
  "seq": 100,
  "conversationId": "uuid",
  "actorId": "uuid",
  "type": "message_created",
  "messageId": "uuid",
  "message": { ... }
}
```

- `message`: `DirectMessage`-Objekt (aktueller Zustand) oder null
- `type`: `message_created` | `message_edited` | `message_deleted`

### ChatSyncResponse

Antwort von `GET /chat/sync`:

```json
{
  "data": {
    "events": [
      {
        "seq": 100,
        "conversationId": "uuid",
        "actorId": "uuid",
        "type": "message_created",
        "messageId": "uuid",
        "message": { ... }
      }
    ],
    "conversations": [
      {
        "conversationId": "uuid",
        "unreadCount": 3,
        "lastSeenAt": "2025-01-15 10:25:00",
        "otherLastReadSeq": 42
      }
    ],
    "typing": {
      "uuid-conversation-id": ["uuid-user-1"]
    }
  },
  "meta": {
    "seq": 100,
    "hasMore": false
  }
}
```

- `events`: Alle Nachrichten-Events mit `seq > after` (neu/bearbeitet/gelöscht)
- `conversations`: **Voll-Liste** aller Konversationen (bis 100), nicht nur Delta
- `typing`: Map von Konversations-ID → Array tippender User-IDs
- `meta.seq`: Höchster gesehener Event-seq (für nächsten Sync als `after`-Parameter verwenden)
- `meta.hasMore`: true wenn `limit` erreicht wurde → weiter pollen

## REST-API

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/chat/sync?after=<eventSeq>&limit=` | **Optimierte Sync-Route:** Nachrichten-Events + Unread + Tippzustände |
| GET | `/chat/conversations` | Konversationsliste (letzte Nachricht, Unread, lastSeenAt) |
| POST | `/chat/conversations` | 1:1-Konversation öffnen (idempotent: get-or-create) → 200 (bestehend) oder 201 (neu) |
| GET | `/chat/conversations/{id}` | Konversation + Teilnehmer (lastReadSeq, otherLastReadSeq) |
| GET | `/chat/conversations/{id}/messages?before=<seq>&limit=50` | History (Cursor `before`) |
| POST | `/chat/conversations/{id}/messages` | Senden (`{clientId, type, content, payload?}`) |
| PATCH | `/chat/messages/{id}` | Bearbeiten (nur eigener, 10 Min-Fenster) |
| DELETE | `/chat/messages/{id}` | Löschen für alle (Platzhalter "Nachricht gelöscht") |
| POST | `/chat/conversations/{id}/read` | Lesestand setzen (`{seq}`) |
| POST | `/chat/conversations/{id}/typing` | Tippindikator (`{typing: bool}`) |

### Admin: Travel-Gruppenchats

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise erstellen (idempotent) |
| DELETE | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise löschen |
| POST | `/admin/travel/events/{id}/chat` | Gruppenchat für Event erstellen (idempotent) |
| DELETE | `/admin/travel/events/{id}/chat` | Gruppenchat für Event löschen |

**Verhalten:**
- Bei Erstellung wird `ChatConversation` mit `type=group` + Name = Reise-/Event-Name angelegt
- `TravelChat`-Eintrag verknüpft den Chat mit Reise oder Event
- `ChatParticipant` wird aus den aktuellen Reise-/Event-Teilnehmern gespiegelt (via `TravelRelation`/`EventRelation`)
- Idempotent: GET oder POST gibt den bestehenden Chat zurück
- Bei Löschung werden `TravelChat`, `ChatConversation` und assoziierte `ChatParticipant`/`DirectMessage`/`ChatEvent` gelöscht (FK-Cascade)
- Automatischer Sync: `AdminController` ruft `syncTripMembers`/`syncEventMembers` bei Hinzufügen/Entfernen von Teilnehmern auf

## Sync-Flow

1. Client merkt sich höchsten **Event**-`seq`-Wert
2. Pollt `GET /chat/sync?after=<letzterEventSeq>` alle 2–3 s (aktiv) oder bis 30 s (idle)
3. Erhält:
   - `events`: `message_created` (neue Nachricht hinzufügen), `message_edited` (Inhalt/`editedAt` ersetzen), `message_deleted` (als gelöscht markieren)
   - `conversations`: **Voll-Liste** aller Konversationen mit `unreadCount`, `lastSeenAt`, `otherLastReadSeq`
   - `typing`: Tippzustände
4. Presence wird bei jedem Sync aktualisiert (`activeUntil = now + 5 s`)
5. Push wird nur gesendet wenn Empfänger NICHT aktiv pollt

**Hinweis:** `conversations` ist keine Delta-Antwort. Der Server liefert bei jedem Sync-Aufruf den kompletten Stand aller Konversationen (bis `limit=100`). Der Client kann die Liste direkt verwenden, ohne本地en Cache zu mergen.

## Online-Anzeige / Presence

- `ChatPresence.activeUntil` ist **server-intern** für Push-Unterdrückung. Das Feld istClients **nicht** verfügbar.
- Das einzige verfügbare Feld für „war der andere online?" ist `lastSeenAt` (aus `ChatParticipant`). Es zeigt den Zeitpunkt des letzten Seitenaufrufs des anderen Teilnehmers.
- Ein `online`-Feld wird **nicht** Exposed. Clients können `lastSeenAt` verwenden, um z.B. „zuletzt online vor 5 Minuten" anzuzeigen.
- `ChatPresence` könnte in Zukunft für eine heartbeat-basierte Online-Anzeige erweitert werden (z.B. `isActive: true` wenn `activeUntil > now`), ist aber aktuell nicht vorgesehen.

## Lesestatus (Read Receipts)

- Pro Teilnehmer wird `lastReadSeq` geführt. Der Sender sieht „gelesen", sobald `otherLastReadSeq >= msg.seq`.
- Der Sender erhält den Lesestatus über:
  - `conversations[].otherLastReadSeq` im Sync (Voll-Liste)
  - `ChatConversation.otherLastReadSeq` in der Konversationsliste
  - `ChatConversation.otherLastReadSeq` in Detail-Endpoints (`getConversation`, `openConversation`)
- Der Client markiert eigene Nachrichten mit `seq <= otherLastReadSeq` als gelesen.
- `POST /chat/conversations/{id}/read` setzt den eigenen Lesestand; das `seq` wird serverseitig auf das Maximum der Konversation begrenzt (Clamp).

## Idempotenz

- `POST …/messages` mit `clientId`: **UNIQUE-Constraint** `(senderId, clientId)` + serverseitiger Lookup innerhalb derselben Konversation verhindern Duplikate bei Retry (auch unter parallelen Requests). Ein wiederverwendetes `clientId` in einer anderen Konversation wird als neuer Sendeversuch behandelt.

## Nachrichten-Aktionen

- **Bearbeiten**: Nur eigener Sender, innerhalb 10 Minuten. Setzt `editedAt` und erzeugt `message_edited`-Event.
- **Löschen für alle**: Nur Sender. `deletedAt` gesetzt, `content`/`payload` geleert. Empfänger sieht Platzhalter. Erzeugt `message_deleted`-Event.
- **Gelesen**: `lastReadSeq` pro Teilnehmer. Sender sieht "gelesen" wenn `empfänger.lastReadSeq >= msg.seq`.

## Notifications

- Typ `direct_message` in `NotificationService::CONTENT_TEMPLATES`
- **Body**: `"{Absender}: {Vorschau}"` (Vorschau auf 160 Zeichen gekürzt) — Push zeigt damit den Nachrichteninhalt
- **Bündelung**: Eine Notification pro Konversation (`dedupeKey = "chat:<conversationId>"`)
- Coalesced Upsert: Existierende ungelesene Notification wird aktualisiert statt neu anzulegen
- Denylist-Präferenz: `direct_message` mit `customData.userIds` (wie `story_post`)
- **Push-Unterdrückung**: Ein aktiver Empfänger (pollt) erhält weiterhin den In-App-Listeneintrag, aber keinen Push

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

- `CleanupOldDirectMessagesTask`: Löscht Nachrichten **und Events** älter als 90 Tage (gebatcht, LIMIT 1000)
- Räumt verwaiste Konversationen, abgelaufene Presence-Einträge und Tippindikatoren auf
- Intervall: 24 Stunden

## Rate-Limits (per Nutzer)

- 20 Nachrichten/Minute pro Nutzer
- 30/Minute für `…/typing`
- Polling nicht drosseln
- Antwort bei Überschreitung: `429 rate_limit_exceeded`

## Gültige `type`-Werte (aktuell)

- `text` (einziger implementierter Typ)
- `image`, `location` vorbereitet aber nicht implementiert
