# Notifications API

> **Wichtige Datenschutzregel:** Google FCM (Firebase Cloud Messaging) darf nicht verwendet oder integriert werden. Push-Benachrichtigungen laufen ausschließlich über Web Push (VAPID) und UnifiedPush.

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
      "title": "Neue Antwort auf deinen Kommentar",
      "text": "Jemand hat auf deinen Kommentar geantwortet.",
      "data": [
        { "relation": "reply_author", "object": "User", "identifier": "user-reply" },
        { "relation": "comment_author", "object": "User", "identifier": "user-comment" },
        { "relation": "post_author", "object": "User", "identifier": "user-post" },
        { "relation": "parent_comment", "object": "ForumPostComment", "identifier": "comment-id" },
        { "relation": "parent_post", "object": "ForumPost", "identifier": "post-id" },
        { "relation": "parent_forum", "object": "Forum", "identifier": "forum-id" }
      ],
      "isRead": false,
      "createdAt": "2026-08-10 14:30:00"
    }
  ]
}
```

Die API liefert auf Benachrichtigungs-Endpunkten und in Push-Payloads keine Client-Routen aus. `title` und `text` werden von der API aus dem Benachrichtigungstyp generiert; Clients interpretieren `type` und `data`, laden bei Bedarf die referenzierten Ressourcen nach und erzeugen das Routing lokal.

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
  "endpoint": "https://push.example.com/endpoint",
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
  "endpoint": "https://push.example.com/endpoint"
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

### Benachrichtigungs-Präferenzen abrufen

```
GET /notifications/preferences
```

Gibt die Präferenzen des eingeloggten Nutzers für alle vom Endpoint angebotenen
Benachrichtigungstypen zurück. Für Typen ohne gespeicherten Eintrag gilt der
Standard `enabled`.

**Hinweis:** Interne Notification-Typen (z.B. `standalone_event_user_added`, `trip_event_user_added`) werden bei den Präferenzen auf vereinheitlichte Typen gemappt (`event_user_added`, `event_ticket_added`, `event_info_changed`). Der Nutzer sieht nur die vereinheitlichten Typen, die sowohl für Reise-Events als auch für eigenständige Events gelten.

Der Endpoint liefert genau einen Preference-Schlüssel je fachlicher Einstellung:

| Preference-Schlüssel | Gilt für interne Notification-Typen |
|----------------------|-------------------------------------|
| `event_user_added` | `standalone_event_user_added`, `trip_event_user_added` |
| `event_user_added_others` | `standalone_event_user_added_others`, `trip_event_user_added_others` |
| `event_ticket_added` | `standalone_event_ticket_added`, `trip_event_ticket_added` |
| `event_info_changed` | `standalone_event_info_changed`, `trip_event_info_changed` |

Die internen Typen sind beim `PUT` nicht gültig. Clients müssen die
vereinheitlichten Preference-Schlüssel verwenden.

**Authentifizierung:** Erforderlich

**Response 200:**
```json
{
  "data": {
    "forum_comment": { "state": "custom", "customAllowed": true,  "customData": { "forumIds": ["forum-id-9"] } },
    "story_post":    { "state": "enabled", "customAllowed": true,  "customData": null },
    "trip_user_added": { "state": "enabled", "customAllowed": false, "customData": null },
    "event_user_added": { "state": "enabled", "customAllowed": false, "customData": null }
  }
}
```

| Feld | Bedeutung |
|------|-----------|
| `state` | `enabled` (aktiv), `disabled` (deaktiviert) oder `custom` (aktiv mit Denylist) |
| `customAllowed` | Ob dieser Typ `custom` überhaupt unterstützt (serverseitig festgelegt) |
| `customData` | JSON mit der Denylist; nur gesetzt wenn `state=custom` |

### Benachrichtigungs-Präferenzen aktualisieren

```
PUT /notifications/preferences
```

Aktualisiert die Präferenzen des eingeloggten Nutzers (Bulk-Update). Es müssen nur die Typen übermittelt werden, die geändert werden sollen.

**Authentifizierung:** Erforderlich

**Request Body:**
```json
{
  "preferences": [
    { "type": "story_post", "state": "disabled" },
    { "type": "forum_comment", "state": "custom", "customData": { "forumIds": ["forum-id-1", "forum-id-2"] } }
  ]
}
```

**Response 200:** Vollständige Präferenz-Map wie bei `GET /notifications/preferences`.

**Response 400:**
- `preferences_required`: `preferences` fehlt oder ist leer
- `invalid_type`: unbekannter Benachrichtigungstyp
- `invalid_state`: ungültiger State (nicht `enabled`/`disabled`/`custom`)
- `custom_not_allowed`: Typ unterstützt kein `custom`
- `custom_data_required`: `state=custom` ohne `customData`
- `custom_data_invalid`: `customData` hat falsches Format

### Custom-Präferenzen (Denylist)

`custom` bedeutet **aktiv mit Denylist**: Benachrichtigungen werden gesendet,
**außer** die ID der Filter-Relation ist in der Denylist enthalten. Eine
leere Denylist ist erlaubt und entspricht dann `enabled` (alles wird
zugestellt). Die Denylist kann später beliebig erweitert oder wieder
geleert werden, ohne den State zu ändern.

**Format-Regeln (verbindlich für alle Clients):**
- `customData` ist ein JSON-Objekt mit **genau einem** Schlüssel, der sich
  aus dem Benachrichtigungstyp ergibt (siehe Tabelle unten).
- Der Wert ist ein Array aus **nicht-leeren Strings** (IDs). Er darf leer
  sein (`[]`).
- Doppelte IDs werden serverseitig dedupliziert; zusätzliche unbekannte
  Schlüssel werden verworfen.
- Nur die folgenden Typen unterstützen `custom`. Für alle anderen Typen
  sind nur `enabled` und `disabled` gültig.

| Typ | `customData`-Schlüssel | Bedeutung der IDs | Filter-Relation im Notification-`data` |
|-----|------------------------|-------------------|----------------------------------------|
| `forum_comment` | `forumIds` | Forum-IDs, deren Benachrichtigungen unterdrückt werden | `parent_forum` |
| `forum_reply` | `forumIds` | Forum-IDs, deren Benachrichtigungen unterdrückt werden | `parent_forum` |
| `story_post` | `userIds` | Nutzer-IDs (Story-Autoren), deren Stories unterdrückt werden | `story_author` |
| `direct_message` | `userIds` | Nutzer-IDs (Absender), deren Nachrichten unterdrückt werden | `sender` |

**Beispiel Foren (Denylist):**
```json
{ "type": "forum_comment", "state": "custom", "customData": { "forumIds": ["0193c1f1-...", "0193c1f2-..."] } }
```
→ Der Nutzer bekommt `forum_comment`-Benachrichtigungen aus **allen** Foren,
außer aus den beiden genannten.

**Beispiel Stories (Denylist leer):**
```json
{ "type": "story_post", "state": "custom", "customData": { "userIds": [] } }
```
→ Der Nutzer bekommt `story_post`-Benachrichtigungen zu **allen** Stories
aller Autoren (entspricht `enabled`, erleichtert aber dem Client die UI-Logik).

**Hinweis zur Foren-Migration:** Das bisherige per-Forum-Feld `ForumMember.notificationsEnabled` samt Endpunkt `PUT /forums/{id}/members/notifications` ist seit Einführung der Präferenzen **deprecated**. Clients sollen stattdessen `forum_comment`/`forum_reply` mit `state=custom` und `customData.forumIds` verwenden. Das alte Feld bleibt vorerst bestehen, wird aber nicht mehr weiterentwickelt.

## Datenbank-Schema

### Tabelle `Notification`

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `id` | varchar(191) | Primärschlüssel (UUIDv7) |
| `userId` | varchar(191) | Empfänger (FK zu User) |
| `type` | varchar(64) | Typ der Benachrichtigung (z.B. `forum_reply`, `forum_comment`) |
| `dedupeKey` | varchar(191) | Optional. Deduplizierungsschlüssel (z.B. `chat:<conversationId>` für gebündelte Chat-Notifications). Bei Setzung wird eine bestehende ungelesene Notification mit gleichem Key aktualisiert statt neu anzulegen. |
| `title` | varchar(255) | Kurztitel, von der API aus dem Typ generiert; wird an Clients ausgeliefert |
| `body` | text | Anzeigetext, von der API aus dem Typ generiert; wird an Clients als `text` ausgeliefert |
| `data` | json | Strukturierte Relation-Liste für den jeweiligen Benachrichtigungstyp |
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

### Tabelle `NotificationPreference`

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `id` | varchar(191) | Primärschlüssel (UUIDv7) |
| `userId` | varchar(191) | Eigentümer (FK zu User, ON DELETE CASCADE) |
| `type` | varchar(64) | Preference-Schlüssel; Event-Typen werden vereinheitlicht gespeichert |
| `state` | varchar(16) | `enabled`, `disabled` oder `custom` (Default `enabled`) |
| `data` | json | Denylist bei `custom`; sonst null |
| `createdAt` | datetime(3) | Erstellungszeitpunkt (UTC) |
| `updatedAt` | datetime(3) | Änderungszeitpunkt (UTC) |

**Unique:** `(userId, type)` — höchstens ein Eintrag pro Nutzer und Typ. **Kein Eintrag = aktiv.**

## Notification-Typen

> **Übersichtstabelle:** [types.md](./types.md) listet tabellarisch alle Notification-Typen mit Trigger, Empfänger und übermittelten Eigenschaften. Diese Tabelle ist die maßgebliche Übersicht und muss bei jeder Typ-Änderung aktualisiert werden.

Die strukturierten Benachrichtigungstypen und ihre internen Trigger sind in
[types.md](./types.md) vollständig aufgeführt. Der Preferences-Endpoint bietet
für Event-Typen nur die gemeinsamen Schlüssel `event_user_added`,
`event_user_added_others`, `event_ticket_added` und `event_info_changed` an;
die internen Reise-/Standalone-Varianten bleiben davon getrennt.
Nicht unterstützte Typen oder unvollständige/abweichende Relationsdaten werden
beim Erstellen serverseitig mit `InvalidArgumentException` abgelehnt.

Die Forum-Typen werden automatisch in `ForumService::createComment()` getriggert: ein Top-Level-Kommentar erzeugt `forum_comment` für den Post-Autor, eine Antwort erzeugt `forum_reply` für den Autor des beantworteten Kommentars. Eigene Kommentare/Antworten lösen keine Benachrichtigung aus (kein Self-Trigger).

Der Story-Typ wird automatisch in `StoryController::create()` getriggert: eine neue Story erzeugt `story_post` für alle übrigen Nutzer (kein Self-Trigger).

Der Chat-Typ wird automatisch in `DirectMessageService::sendMessage()` getriggert: eine neue Nachricht erzeugt `direct_message` für alle anderen Teilnehmer der Konversation (kein Self-Trigger). Push wird nur gesendet wenn `ChatPresence.activeUntil` des Empfängers in der Vergangenheit liegt. Bündelung via `dedupeKey = "chat:<conversationId>"`.

### `forum_reply`

Benachrichtigt darüber, dass auf einen bestehenden Forum-Kommentar geantwortet wurde. Der Client kann daraus den Forum-Deep-Link lokal als `/forum/{parent_forum.identifier}/{parent_post.identifier}` bzw. nach seiner eigenen Routing-Konvention erzeugen.

| Relation | Objekt | Pflicht | Bedeutung |
|----------|--------|---------|-----------|
| `reply_author` | `User` | Ja | Nutzer, der die neue Antwort erstellt hat |
| `comment_author` | `User` | Ja | Nutzer, dessen Kommentar beantwortet wurde |
| `post_author` | `User` | Ja | Autor des übergeordneten Forum-Posts |
| `parent_comment` | `ForumPostComment` | Ja | Kommentar, auf den geantwortet wurde |
| `parent_post` | `ForumPost` | Ja | Forum-Post, unter dem die Kommentar-Kette liegt |
| `parent_forum` | `Forum` | Ja | Forum, in dem der Post liegt |

**Data-Format:**
```json
[
  { "relation": "reply_author", "object": "User", "identifier": "123456" },
  { "relation": "comment_author", "object": "User", "identifier": "234567" },
  { "relation": "post_author", "object": "User", "identifier": "345678" },
  { "relation": "parent_comment", "object": "ForumPostComment", "identifier": "987654" },
  { "relation": "parent_post", "object": "ForumPost", "identifier": "456789" },
  { "relation": "parent_forum", "object": "Forum", "identifier": "567890" }
]
```

### `forum_comment`

Benachrichtigt darüber, dass ein neuer Top-Level-Kommentar direkt auf einen Forum-Post erstellt wurde. Dieser Typ enthält bewusst keine `parent_comment`-Relation. Der Client kann den Forum-Deep-Link lokal aus `parent_forum` und `parent_post` erzeugen.

| Relation | Objekt | Pflicht | Bedeutung |
|----------|--------|---------|-----------|
| `comment_author` | `User` | Ja | Nutzer, der den neuen Kommentar erstellt hat |
| `post_author` | `User` | Ja | Autor des übergeordneten Forum-Posts |
| `parent_post` | `ForumPost` | Ja | Forum-Post, auf den direkt geantwortet wurde |
| `parent_forum` | `Forum` | Ja | Forum, in dem der Post liegt |

**Data-Format:**
```json
[
  { "relation": "comment_author", "object": "User", "identifier": "123456" },
  { "relation": "post_author", "object": "User", "identifier": "345678" },
  { "relation": "parent_post", "object": "ForumPost", "identifier": "456789" },
  { "relation": "parent_forum", "object": "Forum", "identifier": "567890" }
]
```

### `story_post`

Benachrichtigt darüber, dass eine neue Story veröffentlicht wurde. Empfänger sind alle Nutzer außer dem Autor selbst (kein Self-Trigger). Der Client kann den Story-Feed-Deeplink lokal aus `story_author` und `story` erzeugen.

| Relation | Objekt | Pflicht | Bedeutung |
|----------|--------|---------|-----------|
| `story_author` | `User` | Ja | Nutzer, der die neue Story veröffentlicht hat |
| `story` | `Story` | Ja | Die neue Story |

**Data-Format:**
```json
[
  { "relation": "story_author", "object": "User", "identifier": "123456" },
  { "relation": "story", "object": "Story", "identifier": "987654" }
]
```

### `direct_message`

Benachrichtigt darüber, dass eine neue Direktnachricht in einer 1:1-Konversation eingegangen ist. **Bündelung:** Es wird maximal eine Notification pro Konversation erstellt (`dedupeKey = "chat:<conversationId>"`). Bei weiteren Nachrichten in derselben Konversation wird die bestehende, ungelesene Notification aktualisiert (Titel/Text/Data), statt eine neue anzulegen.

**Push-Unterdrückung:** Der Empfänger erhält nur dann eine Push-Benachrichtigung, wenn sein `ChatPresence.activeUntil` in der Vergangenheit liegt (er nicht aktiv pollt).

| Relation | Objekt | Pflicht | Bedeutung |
|----------|--------|---------|-----------|
| `sender` | `User` | Ja | Nutzer, der die Nachricht gesendet hat |
| `conversation` | `ChatConversation` | Ja | Die Konversation |
| `message` | `DirectMessage` | Ja | Die neue Nachricht |

**Data-Format:**
```json
[
  { "relation": "sender", "object": "User", "identifier": "123456" },
  { "relation": "conversation", "object": "ChatConversation", "identifier": "987654" },
  { "relation": "message", "object": "DirectMessage", "identifier": "456789" }
]
```

**Coalesced Upsert:** Der `NotificationService` prüft bei `dedupeKey` auf eine vorhandene ungelesene Notification mit demselben Key. Existiert eine, wird `title`/`body`/`data` aktualisiert und `createdAt` auf `NOW(3)` gesetzt. Die `data`-Relationen werden dabei überschrieben (nicht gemerged) – der Client sollte die `message`-ID aus der最新的 Notification verwenden.

## Push-Versand

### Web Push (VAPID)

Der API-Server sendet automatisch Web Push-Benachrichtigungen an alle registrierten Web Push-Subscriptions des Empfängers, wenn eine neue Benachrichtigung erstellt wird.

**Payload:**
```json
{
  "id": "01923456-7890-7abc-def0-123456789012",
  "type": "forum_reply",
  "title": "Neue Antwort auf deinen Kommentar",
  "text": "Jemand hat auf deinen Kommentar geantwortet.",
  "data": [
    { "relation": "reply_author", "object": "User", "identifier": "user-reply" },
    { "relation": "comment_author", "object": "User", "identifier": "user-comment" },
    { "relation": "post_author", "object": "User", "identifier": "user-post" },
    { "relation": "parent_comment", "object": "ForumPostComment", "identifier": "comment-id" },
    { "relation": "parent_post", "object": "ForumPost", "identifier": "post-id" },
    { "relation": "parent_forum", "object": "Forum", "identifier": "forum-id" }
  ],
  "createdAt": "2026-08-10 14:30:00"
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
  "title": "Neue Antwort auf deinen Kommentar",
  "text": "Jemand hat auf deinen Kommentar geantwortet.",
  "data": [
    { "relation": "reply_author", "object": "User", "identifier": "user-reply" },
    { "relation": "comment_author", "object": "User", "identifier": "user-comment" },
    { "relation": "post_author", "object": "User", "identifier": "user-post" },
    { "relation": "parent_comment", "object": "ForumPostComment", "identifier": "comment-id" },
    { "relation": "parent_post", "object": "ForumPost", "identifier": "post-id" },
    { "relation": "parent_forum", "object": "Forum", "identifier": "forum-id" }
  ],
  "createdAt": "2026-08-10 14:30:00"
}
```

HTTP 410 vom Distributor → Subscription wird automatisch gelöscht.

> Der Push-Payload entspricht dem reduzierten Benachrichtigungsobjekt aus `GET /notifications` ohne `userId` und `isRead`: `id`, `type`, `title`, `text`, `data`, `createdAt`. Er enthält keine Routen; Titel und Texte sind API-generiert.

### Bereinigung abgelaufener Subscriptions

Die Bereinigung erfolgt **reaktiv** beim Push-Versand: Endpoints, die mit HTTP 410 (oder 404) antworten, werden unmittelbar nach der Fehlerantwort aus `PushSubscription` gelöscht (`sendWebPush` via `MessageSentReport::isSubscriptionExpired()`, `sendUnifiedPush` bei HTTP 410). Ein separater `cleanExpiredSubscriptions()`-Sweep ist bewusst nicht implementiert, da der reaktive Weg keinen zusätzlichen Zustand benötigt und die Abfrage leben­der Endpoints im Batch unzuverlässig wäre.

## Integration in bestehende Controller

Benachrichtigungen werden serverseitig erstellt, indem der `NotificationService` aufgerufen wird:

```php
$this->notificationService->create(
    userId: $recipientUserId,
    type: 'forum_comment',
    title: '', // leer = von der API generiert
    body: '',  // leer = von der API generiert
    data: [
        ['relation' => 'comment_author', 'object' => 'User', 'identifier' => $commentAuthorId],
        ['relation' => 'post_author', 'object' => 'User', 'identifier' => $postAuthorId],
        ['relation' => 'parent_post', 'object' => 'ForumPost', 'identifier' => $postId],
        ['relation' => 'parent_forum', 'object' => 'Forum', 'identifier' => $forumId],
    ],
);
```

Für `forum_reply` verwendet der Aufruf stattdessen die sechs in diesem Typ beschriebenen Relationen einschließlich `reply_author` und `parent_comment`.

Sind `title`/`body` leer, generiert die API sie automatisch aus dem Typ (siehe [types.md](./types.md)). Explizit übergebene Werte (z.B. aus dem Admin-Dashboard) haben Vorrang.

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
