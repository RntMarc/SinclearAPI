# LaMetric Time Integration

Verknüpfung der Sinclear Beyond API mit der [LaMetric Time](https://lametric.com/).
Die Integration ist in mehrere unabhängige LaMetric-Apps aufgeteilt, statt
einer Super-App. Den Anfang macht die **Notification-App**, die eine gebündelte
Zusammenfassung aller ungelesenen Benachrichtigungen auf der Uhr anzeigt.

## Grundprinzip

LaMetric Indicator Apps mit Kommunikationstyp **„Poll"** rufen selbst eine URL
ab. Sinclear liefert dabei JSON im von LaMetric erwarteten Frame-Format
(siehe [My Data DIY](https://help.lametric.com/support/solutions/articles/6000225467-my-data-diy)):

```json
{
  "frames": [
    { "text": "10 neue Chats, 3 neue Foren-Beiträge und 1 neue Reise" }
  ]
}
```

Der Sinclear-Token wird von der Uhr als Query-Parameter `token` an den
Poll-Endpunkt übergeben. Alle LaMetric-Apps eines Nutzers verwenden denselben
Token.

## Token

- **Genau ein Token pro Nutzer**, gültig **1 Jahr** (`LaMetricToken.expiresAt`).
- Im Gegensatz zu MCP-/DAV-Tokens wird der Klartext-Token gespeichert und kann
  **jederzeit erneut abgerufen** werden, um weitere Uhren oder Apps zu
  verknüpfen.
- Kein Auslaufen ohne Ersatz: Ein abgelaufener Token kann durch erneutes
  `PUT /lametric/token` ersetzt werden. Abgelaufene Token werden wöchentlich per
  MySQL-Event (`clean_expired_lametric_tokens`) entfernt.
- Es werden **keine Nachrichteninhalte** übertragen – nur Zähler je Kategorie.
  Der Token ist damit wenig sensitiv.

### Token verwalten (JWT erforderlich)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/lametric/token` | Vorhandenen Token inkl. Klartext abrufen (`token: null`, falls keiner) |
| `PUT` | `/lametric/token` | Token erzeugen oder ersetzen |
| `DELETE` | `/lametric/token` | Token widerrufen |

**GET Response 200:**

```json
{
  "token": {
    "id": "01923456-7890-7abc-def0-123456789012",
    "label": "Wohnzimmer",
    "token": "3f2a...64-hex...",
    "expiresAt": "2027-09-25 12:00:00",
    "lastUsedAt": "2026-09-25 12:05:00",
    "createdAt": "2026-09-25 12:00:00"
  }
}
```

**PUT Request Body (optional):**

```json
{ "label": "Wohnzimmer" }
```

**PUT Response 200:** wie GET, mit neu erzeugtem `token`.

**DELETE Response 204:** Token entfernt (idempotent).

## Notification-App

```
GET /lametric/notification?token=<token>
```

**Authentifizierung:** Kein Bearer-Token. Der persönliche LaMetric-Token wird als
Query-Parameter `token` übergeben (alternativ Header `X-LaMetric-Token` oder
`Authorization: Bearer`).

Der Endpunkt antwortet **immer mit HTTP 200** und LaMetric-Frames.

**Response 200 – ungelesene Benachrichtigungen vorhanden:**

```json
{
  "frames": [
    { "text": "10 neue Chats, 3 neue Foren-Beiträge und 1 neue Reise" }
  ]
}
```

**Response 200 – nichts ungelesen:**

```json
{ "frames": [ { "text": "Alles gelesen" } ] }
```

**Response 200 – Token fehlt oder ist ungültig:** Es wird ein Fehler-Frame
angezeigt, damit der Nutzer direkt auf der Uhr sieht, dass er den Token in
Sinclear Beyond neu erzeugen und in der LaMetric-App eintragen muss.

```json
{ "frames": [ { "text": "LaMetric-Token ungültig oder abgelaufen. Bitte in Sinclear Beyond neu erzeugen." } ] }
```

### Kategorien

Interne Benachrichtigungstypen (siehe `notifications/types.md`) werden zu
folgenden Kategorien gebündelt:

| Kategorie | Interne Typen | Beispieltext (Singular / Plural) |
|-----------|---------------|----------------------------------|
| Chats | `direct_message` | 1 neuer Chat / 5 neue Chats |
| Forum | `forum_reply`, `forum_comment`, `forum_post`, `forum_upvote` | 1 neuer Foren-Beitrag / 3 neue Foren-Beiträge |
| Reisen | `trip_user_added`, `trip_user_added_others`, `trip_ticket_added`, `trip_accommodation_added`, `trip_info_changed`, `trip_subscription_added` | 1 neue Reise / 2 neue Reisen |
| Events | `standalone_event_*`, `trip_event_*` | 1 neues Event / 4 neue Events |
| Stories | `story_post` | 1 neue Story / 2 neue Stories |
| Sonstiges | unbekannte Typen (Fallback) | 1 neue Benachrichtigung / n neue Benachrichtigungen |

Die Phrasen werden in der Reihenfolge der Tabelle mit `, ` und final ` und `
verbunden. Nicht-leere Kategorien werden übersprungen, die Reihenfolge bleibt
erhalten.

## Einrichtung der LaMetric-App (DevZone)

1. Auf https://developer.lametric.com eine **Indicator App** erstellen.
2. Kommunikationstyp **Poll** wählen.
3. Poll-URL: `https://<api-host>/api/v2/lametric/notification`.
4. Ein **benutzerdefiniertes Feld** mit ID `token` (Anzeigename z.B. „Sinclear
   Token") hinzufügen. LaMetric hängt es als Query-Parameter an die Poll-URL.
5. Poll-Intervall festlegen (z.B. 60 s).
6. App veröffentlichen und auf der Uhr installieren.
7. In der LaMetric-App unter den App-Einstellungen den zuvor über
   `PUT /lametric/token` erzeugten Token eintragen.

Weitere Sinclear-Apps (Kalender, Reisen, …) können denselben Token als
`token`-Feld verwenden.

## Sicherheit

- Token im Klartext in `LaMetricToken` gespeichert (bewusste Entscheidung für
  erneutes Anzeigen; keine Nachrichteninhalte werden übertragen).
- Ablauf wird bei jeder Anfrage serverseitig geprüft (`validateToken`).
- `lastUsedAt` wird auf eine Stunde gedrosselt aktualisiert.
- Antworten werden mit `Cache-Control: no-store` ausgeliefert.
- Der Poll-Endpunkt ist absichtlich nicht rate-limitiert, da die Uhr in festen
  Intervallen pollt; die Datenmenge ist minimal.

## Verwandte Dokumentation

- Benachrichtigungstypen: `notifications/types.md`
- Benachrichtigungs-API: `notifications/readme.md`
- OpenAPI: `openapi`-Topic
