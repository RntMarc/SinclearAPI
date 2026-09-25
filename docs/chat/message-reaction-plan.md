# Message Reaction Plan (implementiert)

Reaktionen auf Chat-Nachrichten, umgesetzt über den Centrifugo Publish-Proxy.
Diese Datei beschreibt den **umgesetzten** Stand (der frühere Entwurf mit
REST-Endpunkten und FK auf `Message` ist überholt).

## Transport

Es gibt **keinen REST-Endpoint**. Client-seitige Reaktionen laufen über die
bestehende WebSocket-Verbindung und den Publish-Proxy:

```
Client: sub.publish({ reaction: { messageId, emoji, add } })
  → Centrifugo → POST /api/v2/centrifugo/publish
  → PHP: Participant-Check + Rate-Limit + Persistenz + Summary
  → Centrifugo broadcastet { type: "reaction_updated", messageId, reactions:[…] }
  → alle Clients (inkl. Sender) aktualisieren die Bubble
```

- Explizites `add: true|false` (kein Toggle) → idempotent bei Retry.
- `skip_history: true` (ephemer); Reconnect lädt den Verlauf per REST.
- Rate-Limit: 60/min pro Nutzer (`chat_reaction:{userId}`).

## Datenmodell

```sql
CREATE TABLE MessageReaction (
  id varchar(191) NOT NULL,
  messageId varchar(191) NOT NULL,
  userId varchar(191) NOT NULL,
  emoji varchar(32) NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_reaction_msg_user_emoji (messageId, userId, emoji),
  KEY idx_reaction_message (messageId),
  CONSTRAINT fk_reaction_message FOREIGN KEY (messageId) REFERENCES DirectMessage (id) ON DELETE CASCADE,
  CONSTRAINT fk_reaction_user FOREIGN KEY (userId) REFERENCES User (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Allowlist

Feste, normalisierte Liste (Variation Selectors entfernt):
`👍 ❤ 😂 😮 😢 🎉 🔥 👏`.
Client und Server spiegeln exakt dieselbe Liste.

## DTO / Aggregation

`DirectMessage.reactions` ist eingebettet:

```json
"reactions": [
  {"emoji": "👍", "count": 2, "users": [{"id": "…", "displayName": "…", "avatar": "…"}]}
]
```

Sortiert nach `count` absteigend. Kein `me`-Flag; der Client leitet „me" durch
Abgleich seiner User-ID mit `users` ab. Batch-Load in `getMessages` (kein N+1).

## Beteiligte Komponenten

- `src/Repository/MessageReactionRepository.php`
- `src/Services/MessageReactionService.php`
- `src/Controllers/CentrifugoProxyController.php` (Reaktionszweig)
- `src/Services/DirectMessageService.php` (Einbettung in `formatMessage`)
- `config/dependencies.php`, `openapi.yaml`, `docs/chat/readme.md`

## Nicht enthalten (bewusst)

- Keine Reaction-Notifications, keine Reaktions-Moderation.
- Kein REST-Fallback (Reaktionen sind ein Realtime-Feature; bei deaktiviertem
  Centrifugo nicht verfügbar).
- Keine freien Emojis (nur Allowlist).
