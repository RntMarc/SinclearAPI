# Stories

Die Story-Funktion ermöglicht es Nutzern, kurzlebige Bild-Stories zu veröffentlichen.
Stories sind nach der Veröffentlichung genau **7 Tage** für alle authentifizierten
Nutzer sichtbar und laufen danach automatisch ab (`expiresAt = createdAt + 7 Tage`).

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Das Format ist `YYYY-MM-DD HH:MM:SS` (24h, ohne Zeitzonenindikatoren). Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC vor dem Senden und von UTC in die lokale Zeitzone bei der Anzeige verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `Story` | Eine Story (Bild + optionale Caption) mit `createdAt` und `expiresAt` (7 Tage) |
| `StoryView` | Gesehen-Status: welcher Nutzer hat welche Story wann gesehen (Unique `storyId` + `userId`) |

Migrationen: `database/migrations/create_stories_table.sql` und
`database/migrations/create_story_views_table.sql`.

## Endpunkte (alle authentifiziert, Basis `/api/v2`)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/stories` | Story-Feed: aktive Stories (< 7 Tage), gruppiert nach Nutzer (Name, Avatar, Stories, Gesehen-Status) |
| `POST` | `/stories` | Neue Story erstellen (Body: `image` als Base64, optional `caption`) |
| `GET` | `/stories/{id}` | Einzelne Story inkl. Autor, Gesehen-Status und `viewCount` |
| `DELETE` | `/stories/{id}` | Eigene Story löschen (nur Ersteller oder Admin) |
| `POST` | `/stories/{id}/view` | Story für den aktuellen Nutzer als gesehen markieren (idempotent) |

### Story erstellen (`POST /stories`)

Request-Body:

```json
{
  "image": "<base64: JPEG/PNG/WebP, max. 200 KB, max. 1000x1000 px>",
  "caption": "Optionale Bildunterschrift (max. 1000 Zeichen)"
}
```

Die Bildvalidierung nutzt den zentralen `ImageService` (gleiche Regeln wie bei
Rezepten). Fehlerantworten: `invalid_image`, `invalid_image_encoding`,
`image_too_large`, `invalid_image_format`, `unsupported_image_format`,
`image_dimensions_too_large`, `invalid_caption`.

### Story-Feed (`GET /stories`)

Antwort (gruppiert nach Autor, neueste Story zuerst):

```json
{
  "data": [
    {
      "userId": "550e8400-e29b-41d4-a716-446655440000",
      "displayName": "Marc",
      "avatar": "<base64 Profilbild>",
      "stories": [
        {
          "id": "550e8400-e29b-41d4-a716-446655440001",
          "image": "<base64 Story-Bild>",
          "caption": "Urlaub!",
          "createdAt": "2026-08-15 12:00:00",
          "expiresAt": "2026-08-22 12:00:00",
          "viewed": false
        }
      ]
    }
  ]
}
```

Abgelaufene Stories (`expiresAt <= now`) werden weder im Feed noch einzeln
eingeschränkt angezeigt – `GET /stories/{id}` liefert sie weiterhin, solange
sie nicht gelöscht wurden.

## Berechtigungen

- Alle authentifizierten Nutzer dürfen Stories **erstellen, lesen und als gesehen markieren**.
- Nur der **Ersteller** (oder ein Admin) darf eine Story **löschen**.
- `StoryView`-Einträge werden beim Löschen der Story automatisch entfernt (FK `ON DELETE CASCADE`).

## Sichtbarkeitsregel (7 Tage)

Die Sichtbarkeit wird ausschließlich über `expiresAt` gesteuert:
- Die API setzt beim Erstellen `expiresAt = createdAt + 7 Tage` (SQL: `DATE_ADD(NOW(3), INTERVAL 7 DAY)`).
- Der Feed filtert mit `expiresAt > NOW(3)`.
- Abgelaufene Stories können weiterhin per `DELETE /stories/{id}` vom Ersteller gelöscht werden.
