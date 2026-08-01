# Moderations-Anfragen (Moderation Requests)

Das Melde- und Anfragensystem erlaubt es Nutzern, fremde Inhalte zu melden
(`report`) oder für eigene Inhalte Aktionen beim Administrator zu beantragen
(`deletion`, `other`). Es ersetzt die direkte Löschung von Rezepten,
Entdecken-Orten und Forumsbeiträgen nach Ablauf des 30-Minuten-Fensters.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Das Format ist `YYYY-MM-DD HH:MM:SS` (24h, ohne Millisekunden, ohne Zeitzonenindikatoren). Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC vor dem Senden und von UTC in die lokale Zeitzone bei der Anzeige verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank-Tabelle

| Tabelle | Beschreibung |
|---------|-------------|
| `ModerationRequest` | Anfragen/Meldungen mit Typ, Objekt-Referenz, Status und Admin-Kommentar |

Schema: `events/moderation_request_schema.sql`

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | varchar(191) | Eindeutige ID (UUIDv7) |
| `userId` | varchar(191) | ID des Nutzers, der die Anfrage abgeschickt hat |
| `requestType` | enum | `report`, `deletion`, `other` |
| `objectType` | enum | `user`, `forum_post`, `recipe`, `explore_place` |
| `objectId` | varchar(191) | ID des Objekts, auf das sich die Anfrage bezieht |
| `message` | text | Ausführliche Beschreibung des Anliegens |
| `status` | enum | Bearbeitungsstatus (siehe unten) |
| `adminComment` | text | Kommentar/Anmerkung des Admins (Rückmeldung an den Nutzer) |
| `createdAt` / `updatedAt` | datetime(3) | Zeitstempel (UTC) |

### Enums

**`requestType` (Art der Anfrage):**

| Wert | Bedeutung |
|------|-----------|
| `report` | Meldung eines fremden Inhalts |
| `deletion` | Bitte um Löschung des eigenen Inhalts |
| `other` | Sonstige Anfrage |

**`objectType` (Art des Objekts):**

| Wert | Bedeutung |
|------|-----------|
| `user` | Nutzerprofil |
| `forum_post` | Forumsbeitrag |
| `recipe` | Rezept |
| `explore_place` | Entdecken-Ort |

**`status` (Bearbeitungsstatus):**

| Wert | Bedeutung |
|------|-----------|
| `unread` | Ungelesen |
| `read` | Gelesen |
| `in_work` | In Bearbeitung |
| `external_contact` | Externer Kontakt nötig |
| `public_decision` | Öffentliche Entscheidung |
| `accepted` | Akzeptiert |
| `denied` | Abgelehnt |
| `postponed` | Verschoben |

## Regeln

- **Eigene Inhalte dürfen nicht gemeldet werden:** `report` auf ein Objekt,
  dessen Ersteller der Absender selbst ist, wird mit `cannot_report_own` (403)
  abgelehnt.
- **Fremde Inhalte dürfen nicht zur Löschung beantragt werden:**
  `deletion` auf ein Objekt, dessen Ersteller nicht der Absender ist, wird mit
  `cannot_request_deletion_foreign` (403) abgelehnt.
- `other` unterliegt keiner Besitzprüfung.
- Das referenzierte Objekt muss existieren, sonst `object_not_found` (404).

## Endpunkte

### Anfrage erstellen

```
POST /moderation-requests
Authorization: Bearer <JWT>
```

```json
{
  "requestType": "report",
  "objectType": "recipe",
  "objectId": "550e8400-e29b-41d4-a716-446655440000",
  "message": "Das Rezept enthält falsche Angaben zu Allergenen."
}
```

**Antwort (201):**

```json
{
  "data": {
    "id": "8f14e45f-ceea-4668-9c5a-2f2a1a2b3c4d",
    "userId": "3f0f5c2e-...",
    "userDisplayName": "max",
    "userImage": null,
    "requestType": "report",
    "objectType": "recipe",
    "objectId": "550e8400-e29b-41d4-a716-446655440000",
    "message": "Das Rezept enthält falsche Angaben zu Allergenen.",
    "status": "unread",
    "adminComment": null,
    "createdAt": "2026-08-01 12:00:00",
    "updatedAt": "2026-08-01 12:00:00"
  }
}
```

**Fehlercodes:**

| Code | HTTP | Beschreibung |
|------|------|-------------|
| `message_required` | 400 | `message` fehlt oder ist leer |
| `invalid_request_type` | 400 | Ungültiger `requestType` |
| `invalid_object_type` | 400 | Ungültiger `objectType` |
| `object_not_found` | 404 | Referenziertes Objekt existiert nicht |
| `cannot_report_own` | 403 | Eigene Inhalte dürfen nicht gemeldet werden |
| `cannot_request_deletion_foreign` | 403 | Fremde Inhalte dürfen nicht zur Löschung beantragt werden |

### Eigene Anfragen abrufen

```
GET /moderation-requests/mine?page=1&limit=20
Authorization: Bearer <JWT>
```

Gibt alle Anfragen des Nutzers zurück – inklusive `status` und
`adminComment` des Admins.

**Antwort (200):** Paginierte Liste (`data`, `meta`), Elemente wie oben.

## Admin-Bereich

Die Bearbeitung erfolgt über das Admin-Dashboard (`/api/v2/admin/moderation-requests`).
Dort gibt es Filter nach Bearbeitungsstatus, Art des Objekts und Art der Anfrage.
Im Detail einer Anfrage können Status und Admin-Kommentar gesetzt werden.

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| `GET` | `/admin/moderation-requests` | HTML-Übersicht mit Filtern (`status`, `objectType`, `requestType`) |
| `GET` | `/admin/moderation-requests/{id}` | HTML-Detailseite |
| `POST` | `/admin/moderation-requests/{id}/update` | Status + Admin-Kommentar setzen |

`POST /admin/moderation-requests/{id}/update` Body:

```json
{
  "status": "accepted",
  "adminComment": "Vielen Dank für die Meldung, der Beitrag wurde entfernt."
}
```

`adminComment` ist optional (leerer String wird als `null` gespeichert).

## Löschfenster der Bereiche

Die direkten Lösch-Endpunkte der betroffenen Bereiche sind auf
**30 Minuten nach dem Erstellen** eingeschränkt (danach `edit_window_expired`,
HTTP 403). Administratoren können immer löschen.

| Bereich | Endpunkt | Dokumentation |
|---------|----------|---------------|
| Rezepte | `DELETE /recipes/{id}` | `docs/recipes/readme.md` |
| Entdecken | `DELETE /explore/{id}` | `docs/explore/readme.md` |
| Forumsbeiträge | `DELETE /forums/{id}/posts/{postId}` | `docs/forum/readme.md` |
| Forums-Kommentare | `DELETE /forums/{id}/posts/{postId}/comments/{commentId}` | `docs/forum/readme.md` |

Bewertungen bleiben davon unberührt und können weiterhin jederzeit vom
Eigentümer gelöscht werden.
