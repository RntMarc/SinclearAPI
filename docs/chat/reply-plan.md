# Message Reply Plan (implementiert)

Antworten auf Chat-Nachrichten, umgesetzt über den Centrifugo Publish-Proxy.
Eine Antwort ist eine normale Nachricht mit `replyToMessageId`; das Zitat wird
serverseitig eingebettet und reitet im `message_created`-Event mit.

## Transport

Nachrichten (und damit auch Antworten) werden **nicht über REST** gesendet.
Der Client publiziert über die bestehende WebSocket-Verbindung:

```
Client: sub.publish({ message: { clientId, type, content, replyToMessageId? } })
  → Centrifugo → POST /api/v2/centrifugo/publish
  → PHP: Participant-Check + Validierung + Persistenz + Notifications
  → Proxy antwortet { result: { data: { type:"message_created", message:{…, replyToMessageId, replyTo } } } }
  → Centrifugo broadcastet an alle Subscriber (inkl. Sender)
```

- Der Server ruft intern `sendMessage(..., publishToCentrifugo: false)` auf →
  genau **ein** Broadcast pro Nachricht.
- Nicht ephemer (kein `skip_history`): in Centrifugo-History, recovery-fähig.
- Der REST-Endpoint `POST /chat/conversations/{id}/messages` wurde entfernt.
- Idempotenz weiterhin über `clientId` (UNIQUE `(senderId, clientId)`).
- Rate-Limit Senden: 20/min (`chat_send:{userId}`).

## Datenmodell

```sql
ALTER TABLE DirectMessage
  ADD COLUMN replyToMessageId varchar(191) NULL,
  ADD KEY idx_dm_reply_to (replyToMessageId),
  ADD CONSTRAINT fk_dm_reply_to FOREIGN KEY (replyToMessageId)
      REFERENCES DirectMessage (id) ON DELETE SET NULL;
```

`ON DELETE SET NULL` übersteht den 90-Tage-Hard-Delete des Cron (Antwort bleibt,
Zitat-Verweis entfällt). Soft-Delete (`deletedAt`) lässt den Verweis bestehen und
wird als „gelöscht" dargestellt.

## DTO / Embed

`DirectMessage` trägt `replyToMessageId` und `replyTo`:

```json
"replyTo": {
  "id": "uuid", "seq": 12, "senderId": "uuid",
  "sender": {"id": "…", "displayName": "…", "avatar": "…"},
  "type": "text", "content": "gekürzter Text …", "deleted": false
}
```

- **Kürzung:** `REPLY_PREVIEW_LENGTH = 150` Zeichen + `…` (Client zusätzlich 2 Zeilen).
- Gelöschte Elternnachricht → `deleted: true`, `content: ""`.
- Batch-Load in `getMessages` (`findByIds`), kein N+1.

## Beteiligte Komponenten (API)

- `database/migrations/*_add_directmessage_reply.sql`
- `src/Repository/DirectMessageRepository.php` (`create`, `findByIds`)
- `src/Services/DirectMessageService.php` (`resolveReplyTarget`, `formatMessage`, `formatReplyTo`, `sendMessage`-Flag)
- `src/Controllers/CentrifugoProxyController.php` (Message-Branch, Typing-Guard, `invalid_publish`)
- `config/routes.php` (REST-Send entfernt), `openapi.yaml`, `docs/chat/readme.md`, `docs/chat/centrifugo.md`

## Centrifugo-Config

- `channel.proxy.publish.timeout: "10s"` (Default 1s reicht für DB-Write +
  `presence()` + synchrone Push-Sends nicht).
- `publish_proxy_enabled: true` war bereits aktiv. Keine weitere Änderung.

## Nicht enthalten (bewusst)

- Kein REST-Fallback für den Nachrichtenversand (Centrifugo ist der Transport).
- Keine Reply-spezifische Notification (die Nachricht ist eine normale Nachricht).
- Kein eigener Event-Typ: Zitate werden Client-seitig bei `message_edited`/
  `message_deleted` inline nachgezogen.
