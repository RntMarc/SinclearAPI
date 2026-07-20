# Forum

Das Forum-Modul ermöglicht es Nutzern, in thematischen Foren Posts zu erstellen,
sich zu vernetzen und zu interagieren. Foren werden von Administratoren erstellt,
Nutzer können beitreten und eigene Beiträge verfassen.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `Forum` | Foren mit Name, Beschreibung und optionalem Bild |
| `ForumMember` | Mitgliedschaft von Nutzern in Foren inkl. Benachrichtigungseinstellung |
| `FeedPosts` | Beiträge in Foren mit typisiertem JSON-Inhalt |
| `FeedPostVote` | Upvotes von Nutzern für Beiträge (1x pro Nutzer pro Beitrag) |
| `FeedPostComment` | Kommentare zu Beiträgen (mit Verschachtelung via parentId) |

## Post-Typen

| Typ | Beschreibung |
|-----|-------------|
| `text` | Reiner Textinhalt |
| `music` | Textanmerkungen + Liste von Streaming-URLs |
| `video` | Textanmerkungen + Liste von Video-URLs |
| `web` | Textanmerkungen + Liste von beliebigen URLs |

## JSON-Content-Strukturen

### text
```json
{ "text": "string (required)" }
```

### music
```json
{
  "text": "string (optional)",
  "urls": [
    { "platform": "spotify|apple_music|youtube_music|youtube|other", "url": "string" }
  ]
}
```

### video
```json
{
  "text": "string (optional)",
  "urls": [
    { "platform": "youtube|peertube|odysee|tv_mediathek|other", "url": "string" }
  ]
}
```

### web
```json
{
  "text": "string (optional)",
  "urls": ["string (URL)", "string (URL)", ...]
}
```

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/forums` | JWT | Alle Foren auflisten (`all=1` für inkl. Reise-Foren) |
| `POST` | `/forums` | JWT + Admin | Neues Forum erstellen |
| `GET` | `/forums/{id}` | JWT | Forum-Details abrufen |
| `PUT` | `/forums/{id}` | JWT + Admin | Forum bearbeiten |
| `DELETE` | `/forums/{id}` | JWT + Admin | Forum löschen |
| `POST` | `/forums/{id}/members` | JWT | Forum beitreten |
| `DELETE` | `/forums/{id}/members` | JWT | Forum verlassen |
| `GET` | `/forums/{id}/members` | JWT | Mitglieder auflisten |
| `PUT` | `/forums/{id}/members/notifications` | JWT | Benachrichtigung umschalten |
| `GET` | `/forums/{id}/posts` | JWT | Posts im Forum (paginiert) |
| `POST` | `/forums/{id}/posts` | JWT | Post erstellen (nur Mitglieder) |
| `GET` | `/forums/{id}/posts/{postId}` | JWT | Einzelnen Post abrufen |
| `PUT` | `/forums/{id}/posts/{postId}` | JWT | Post bearbeiten (nur Eigentümer) |
| `DELETE` | `/forums/{id}/posts/{postId}` | JWT | Post löschen |
| `POST` | `/forums/{id}/posts/{postId}/vote` | JWT | Post upvoten |
| `DELETE` | `/forums/{id}/posts/{postId}/vote` | JWT | Vote zurückziehen |
| `GET` | `/forums/{id}/posts/{postId}/comments` | JWT | Kommentare abrufen (Baumstruktur) |
| `POST` | `/forums/{id}/posts/{postId}/comments` | JWT | Kommentar erstellen |
| `PUT` | `/forums/{id}/posts/{postId}/comments/{commentId}` | JWT | Kommentar bearbeiten |
| `DELETE` | `/forums/{id}/posts/{postId}/comments/{commentId}` | JWT | Kommentar löschen |

## Reise-verknüpfte Foren

Foren können mit einer Reise verknüpft werden (über `TravelTrip.forumId`).
Diese Foren werden in der öffentlichen Foren-Liste (`GET /forums`) **nicht**
angezeigt, es sei denn, der `all=1`-Parameter wird gesetzt (nur Administratoren).

**Verhalten:**
- Mitreisende der Reise sind automatisch Mitglieder des verknüpften Forums.
- Sie sehen das Forum in ihrer persönlichen Foren-Ansicht (wenn sie die
  entsprechende Forum-ID besitzen) und können dort normal posten.
- Nicht-Mitglieder der Reise können dem Forum nicht beitreten, da es in
  der öffentlichen Liste nicht auftaucht.
- Die Reise-Detail-API (`GET /trips/{id}`) gibt `forumId` und `forum`-Objekt
  zurück, damit der Client den Forum-Tab anzeigen kann.

## Foren verwalten

### Foren auflisten
```
GET /forums?page=1&limit=20
→ 200 { "data": [...], "meta": { "page": 1, "limit": 20, "total": 5, "totalPages": 1 } }
```

### Forum erstellen (Admin)
```
POST /forums
Body: { "name": "Musik-Tausch", "description": "Tauscht eure Lieblingssongs", "image": null }
→ 201 { "data": { "id": "...", "name": "Musik-Tausch", ... } }
```

**Forum-Bild:**
Das optionale `image`-Feld akzeptiert ein Base64-kodiertes Bild.

| Eigenschaft | Limit |
|-------------|-------|
| Dateigröße (Base64-decodiert) | Max. 200 KB |
| Erlaubte Formate | JPEG, PNG, WebP |
| Max. Breite | 1000 Pixel |
| Max. Höhe | 1000 Pixel |

**Beispiel-Request:**
```json
{
  "name": "Fotografie",
  "description": "Fotografie-Tipps und -Tricks",
  "image": "/9j/4AAQSkZJRgABAQEASABIAAD..."
}
```

**Bild entfernen:**
```json
{
  "image": null
}
```

**Fehlercodes:**

| Code | Beschreibung |
|------|-------------|
| `invalid_image` | Ungültiges Bild oder leerer String |
| `invalid_image_encoding` | Base64-Dekodierung fehlgeschlagen |
| `image_too_large` | Dateigröße überschreitet 200 KB |
| `invalid_image_format` | Datei ist kein gültiges Bild |
| `unsupported_image_format` | Format nicht erlaubt (nur JPEG, PNG, WebP) |
| `image_dimensions_too_large` | Abmessungen überschreiten 1000x1000 Pixel |

### Forum-Details abrufen
```
GET /forums/{id}
→ 200 { "data": { "id": "...", "name": "...", "memberCount": 12, "isMember": true, "notificationsEnabled": true, ... } }
```

Die Antwort enthält zusätzlich `isMember` und `notificationsEnabled` für den eingeloggten Nutzer.

## Mitgliedschaft

### Forum beitreten
```
POST /forums/{id}/members → 204
```

### Forum verlassen
```
DELETE /forums/{id}/members → 204
```

### Mitglieder auflisten
```
GET /forums/{id}/members
→ 200 { "data": [{ "userId": "...", "displayName": "...", "notificationsEnabled": true, ... }] }
```

### Benachrichtigung umschalten
```
PUT /forums/{id}/members/notifications
Body: { "notificationsEnabled": false }
→ 204
```

## Posts

### Posts auflisten
```
GET /forums/{id}/posts?page=1&limit=20
→ 200 { "data": [...], "meta": { "page": 1, "limit": 20, "total": 42, "totalPages": 3 } }
```

Jeder Eintrag enthält:
- `upvoteCount`: Anzahl der Upvotes
- `commentCount`: Anzahl der Kommentare
- `hasVoted`: Ob der aktuelle Nutzer bereits gevotet hat

### Post erstellen
```
POST /forums/{id}/posts
Body: { "type": "music", "content": { "text": "Schaut euch das an!", "urls": [{ "platform": "spotify", "url": "https://open.spotify.com/..." }] } }
→ 201 { "data": { "id": "...", "type": "music", "content": { ... }, ... } }
```

**Bedingungen:**
- Nur Mitglieder des Forums dürfen posten
- `type` ist optional, Default ist `text`
- `content` muss der Struktur des jeweiligen Typs entsprechen

### Post bearbeiten
```
PUT /forums/{id}/posts/{postId}
Body: { "content": { "text": "Bearbeiteter Text" } }
→ 200 { "data": { ... } }
```

Nur der Eigentümer darf seinen Post bearbeiten.

### Post löschen
```
DELETE /forums/{id}/posts/{postId} → 204
```

**Löschberechtigung:**

| Bedingung | Erlaubt? |
|-----------|----------|
| Admin | Ja (immer) |
| Eigentümer + ≤10 Min. | Ja |
| Eigentümer + >10 Min. + keine Kommentare | Ja |
| Eigentümer + >10 Min. + Kommentare vorhanden | Nein |
| Fremder Nutzer | Nein |

## Upvotes

Jeder Nutzer kann pro Post genau einmal upvoten. Das Setzen eines
bereits vorhandenen Upvotes gibt `409 Conflict` zurück.

```
POST /forums/{id}/posts/{postId}/vote → 204
DELETE /forums/{id}/posts/{postId}/vote → 204
```

## Kommentare

Nutzer können zu jedem Post Kommentare verfassen und aufeinander antworten.
Kommentare werden als verschachtelte Baumstruktur zurückgegeben.

### Kommentare abrufen
```
GET /forums/{id}/posts/{postId}/comments
→ 200 { "data": [...], "meta": { "total": 5 } }
```

### Kommentar erstellen
```
POST /forums/{id}/posts/{postId}/comments
Body: { "text": "Toller Song!", "parentId": null }
→ 201 { "data": { "id": "...", "text": "Toller Song!", ... } }
```

### Kommentar bearbeiten
```
PUT /forums/{id}/posts/{postId}/comments/{commentId}
Body: { "text": "Bearbeiteter Kommentar" }
→ 200 { "data": { ... } }
```

**Bedingungen:**
- Nur der Eigentümer darf bearbeiten
- Max. 10 Minuten nach Erstellung

### Kommentar löschen
```
DELETE /forums/{id}/posts/{postId}/comments/{commentId} → 204
```

**Verhalten:**
- **Hat Antworten:** Soft-Delete — `text` wird auf `NULL` gesetzt, Struktur bleibt erhalten
- **Keine Antworten:** Hard-Delete — Eintrag wird komplett entfernt
- Leere Eltern-Kommentare werden rekursiv mitgelöscht

**Löschberechtigung:**
- Eigentümer des Kommentars (≤10 Min. oder keine Antworten)
- Administratoren (immer)
