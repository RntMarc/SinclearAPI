# Chat / Direktnachrichten

1:1-Direktnachrichten zwischen Nutzern. Text-only (Bilder/Standort vorbereitet, aber nicht implementiert).

## Architektur

- **Wahrheit**: `DirectMessage`-Tabelle mit globalem monotonen `seq`-Cursor
- **Realtime**: Short Polling via `GET /chat/sync?after=<seq>` (adaptiv 2–3 s aktiv, 30 s idle)
- **Push**: Web Push + UnifiedPush, unterdrückt wenn Empfänger aktiv pollt (`ChatPresence`)
- **Kein E2EE**: Server kennt Klartext, Push darf Inhalte zeigen
- **Aufbewahrung**: 90 Tage, automatisch via Cron

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `ChatConversation` | Konversation (type: direct/group) |
| `ChatParticipant` | Teilnehmer + `lastReadSeq` + `lastSeenAt` |
| `DirectMessage` | Nachrichten mit `seq` (globaler Sync-Cursor) |
| `ChatPresence` | Push-Unterdrückung (`activeUntil`) |
| `ChatTyping` | Tippindikator (ephemer, läuft nach 5 s ab) |

## REST-API

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/chat/sync?after=<seq>&limit=` | **Optimierte Sync-Route:** alle neuen Nachrichten + Unread + Tippzustände |
| GET | `/chat/conversations` | Konversationsliste (letzte Nachricht, Unread, lastSeenAt) |
| POST | `/chat/conversations` | 1:1-Konversation öffnen (idempotent: get-or-create) |
| GET | `/chat/conversations/{id}` | Konversation + Teilnehmer |
| GET | `/chat/conversations/{id}/messages?before=<seq>&limit=50` | History (Cursor `before`) |
| POST | `/chat/conversations/{id}/messages` | Senden (`{clientId, type, content, payload?}`) |
| PATCH | `/chat/messages/{id}` | Bearbeiten (nur eigener, 10 Min-Fenster) |
| DELETE | `/chat/messages/{id}` | Löschen für alle (Platzhalter "Nachricht gelöscht") |
| POST | `/chat/conversations/{id}/read` | Lesestand setzen (`{seq}`) |
| POST | `/chat/conversations/{id}/typing` | Tippindikator (`{typing: bool}`) |

## Sync-Flow

1. Client merkt sich höchsten `seq`-Wert
2. Pollt `GET /chat/sync?after=<letzterSeq>` alle 2–3 s (aktiv) oder bis 30 s (idle)
3. Erhält: neue Nachrichten, geänderte Unread-Zähler, Tippzustände
4. Presence wird bei jedem Sync aktualisiert (`activeUntil = now + 5 s`)
5. Push wird nur gesendet wenn Empfänger NICHT aktiv pollt

## Idempotenz

- `POST …/messages` mit `clientId`: Unique-Lookup `(senderId, clientId)` verhindert Duplikate bei Retry

## Nachrichten-Aktionen

- **Bearbeiten**: Nur eigener Sender, innerhalb 10 Minuten. Setzt `editedAt`.
- **Löschen für alle**: Nur Sender. `deletedAt` gesetzt, `content`/`payload` geleert. Empfänger sieht Platzhalter.
- **Gelesen**: `lastReadSeq` pro Teilnehmer. Sender sieht "gelesen" wenn `empfänger.lastReadSeq >= msg.seq`.

## Notifications

- Typ `direct_message` in `NotificationService::CONTENT_TEMPLATES`
- **Bündelung**: Eine Notification pro Konversation (`dedupeKey = "chat:<conversationId>"`)
- Coalesced Upsert: Existierende ungelesene Notification wird aktualisiert statt neu anzulegen
- Denylist-Präferenz: `direct_message` mit `customData.userIds` (wie `story_post`)

## Cron

- `CleanupOldDirectMessagesTask`: Löscht Nachrichten älter als 90 Tage (gebatcht, LIMIT 1000)
- Räumt verwaiste Konversationen, abgelaufene Presence-Einträge und Tippindikatoren auf
- Intervall: 24 Stunden

## Rate-Limits

- 20 Nachrichten/Minute pro Nutzer (via `RateLimiter`)
- 30/Minute für `…/typing`
- Polling nicht drosseln

## Gültige `type`-Werte (aktuell)

- `text` (einziger implementierter Typ)
- `image`, `location` vorbereitet aber nicht implementiert
