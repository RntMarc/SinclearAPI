# Chat / Direktnachrichten

1:1-Direktnachrichten zwischen Nutzern. Text-only (Bilder/Standort vorbereitet, aber nicht implementiert).

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
| `ChatPresence` | Push-Unterdrückung (`activeUntil`) |
| `ChatTyping` | Tippindikator (ephemer, läuft nach 5 s ab) |

## REST-API

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/chat/sync?after=<eventSeq>&limit=` | **Optimierte Sync-Route:** Nachrichten-Events + Unread + Tippzustände |
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

1. Client merkt sich höchsten **Event**-`seq`-Wert
2. Pollt `GET /chat/sync?after=<letzterEventSeq>` alle 2–3 s (aktiv) oder bis 30 s (idle)
3. Erhält:
   - `events`: `message_created` (neue Nachricht hinzufügen), `message_edited` (Inhalt/`editedAt` ersetzen), `message_deleted` (als gelöscht markieren)
   - `conversations`: geänderte Unread-Zähler, `lastSeenAt`, `otherLastReadSeq` (Lesestatus des Gegenübers)
   - `typing`: Tippzustände
4. Presence wird bei jedem Sync aktualisiert (`activeUntil = now + 5 s`)
5. Push wird nur gesendet wenn Empfänger NICHT aktiv pollt

## Lesestatus (Read Receipts)

- Pro Teilnehmer wird `lastReadSeq` geführt. Der Sender sieht „gelesen", sobald `otherLastReadSeq >= msg.seq`.
- Der Sender erhält den Lesestatus über `conversations[].otherLastReadSeq` im Sync bzw. `ChatConversation.otherLastReadSeq` in der Konversationsliste. Der Client markiert eigene Nachrichten mit `seq <= otherLastReadSeq` als gelesen.
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
