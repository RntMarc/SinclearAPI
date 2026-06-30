# Feedback (Funktionsvorschläge & Bug-Reports)

Die Feedback-Funktion ermöglicht es Nutzern, Funktionsvorschläge zu erstellen
und gegenseitig zu bewerten. Vorschläge werden nach Beliebtheit (Upvotes)
sortiert angezeigt. Zudem können Nutzer Bug-Reports einsenden, die per E-Mail
an den Administrator weitergeleitet werden.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `FeedbackSuggestion` | Funktionsvorschläge mit Titel, Beschreibung und Status |
| `FeedbackVote` | Upvotes von Nutzern für Vorschläge (1x pro Nutzer pro Vorschlag) |
| `FeedbackComment` | Kommentare zu Vorschlägen (mit Verschachtelung via parentId) |

## Status-Werte

| Status | Beschreibung |
|--------|-------------|
| `submitted` | Standard. Vorschlag eingereicht |
| `planned` | Wird in Planung aufgenommen |
| `next` | Nächstes Feature |
| `in_progress` | In Entwicklung |
| `done` | Umgesetzt |
| `cancelled` | Abgebrochen |
| `rejected` | Abgelehnt |
| `later` | Später eventuell |

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `POST` | `/feedback/bug-report` | JWT | Bug-Report per E-Mail an Admin senden |
| `GET` | `/feedback/suggestions` | JWT | Alle Vorschläge (paginiert, sortiert nach Upvotes) |
| `POST` | `/feedback/suggestions` | JWT | Neuen Vorschlag erstellen |
| `DELETE` | `/feedback/suggestions/{id}` | JWT | Vorschlag löschen (Eigentümer + <3 Upvotes oder Admin) |
| `POST` | `/feedback/suggestions/{id}/vote` | JWT | Upvote abgeben (1x pro Nutzer) |
| `DELETE` | `/feedback/suggestions/{id}/vote` | JWT | Upvote zurückziehen |
| `PUT` | `/feedback/suggestions/{id}/status` | JWT + Admin | Status ändern (nur Admin) |
| `GET` | `/feedback/suggestions/{id}/comments` | JWT | Kommentare abrufen (verschachtelte Baumstruktur) |
| `POST` | `/feedback/suggestions/{id}/comments` | JWT | Kommentar erstellen (Top-Level oder Antwort) |
| `PUT` | `/feedback/suggestions/{id}/comments/{commentId}` | JWT | Kommentar bearbeiten (max. 10 Min.) |
| `DELETE` | `/feedback/suggestions/{id}/comments/{commentId}` | JWT | Kommentar löschen (Soft- oder Hard-Delete) |

## Bug-Reports

Nutzer können Bug-Reports einsenden, die per E-Mail an den Administrator
weitergeleitet werden. Die E-Mail enthält Nutzer-Informationen sowie
optional die App-Version und Build-Nummer.

```
POST /feedback/bug-report
Body: { "text": "Die App stürzt beim Öffnen des Profils ab.", "version": "0.5.0", "buildNumber": 5 }
→ 200 { "data": { "sent": true } }
```

### Parameter

| Feld | Typ | Pflicht | Beschreibung |
|------|-----|---------|-------------|
| `text` | string | Ja | Freitext-Beschreibung des Bugs |
| `version` | string | Nein | App-Version (z.B. "0.5.0") |
| `buildNumber` | integer | Nein | Build-Nummer (z.B. 5) |
| `image` | string | Nein | Base64-kodiertes Screenshot-Bild (JPEG/PNG/WebP, max. 200 KB, max. 4000x4000 px) |

### Antworten

| Status | Beschreibung |
|--------|-------------|
| `200` | Bug-Report erfolgreich versendet |
| `400` | Leerer Text (`text_required`), ungültiges Bild (`invalid_image`, `image_too_large`, `invalid_image_format`, `unsupported_image_format`, `image_dimensions_too_large`) |
| `401` | Nicht autorisiert |
| `500` | E-Mail-Versand fehlgeschlagen (`mail_failed`) |

### Hinweis zum Screenshot

Das optionale `image`-Feld akzeptiert ein Base64-kodiertes Bild. Dieses wird
ausschließlich als E-Mail-Anhang an den Administrator versendet und **nirgends**
auf dem Server oder in der Datenbank gespeichert. Nach dem Versand wird der
Speicher sofort freigegeben.

## Vorschlag erstellen

```
POST /feedback/suggestions
Body: { "title": "Dunkelmodus", "description": "App im Dark Mode betreiben" }
→ 201 { "data": { "id": "...", "title": "Dunkelmodus", ... } }
```

### Parameter

| Feld | Typ | Pflicht | Beschreibung |
|------|-----|---------|-------------|
| `title` | string | Ja | Kurze Überschrift (max. 255 Zeichen) |
| `description` | string | Nein | Detaillierte Beschreibung |

## Vorschläge auflisten

```
GET /feedback/suggestions?page=1&limit=20
→ 200 { "data": [...], "meta": { "page": 1, "limit": 20, "total": 42, "totalPages": 3 } }
```

Die Ergebnisse sind nach Upvote-Anzahl (absteigend) und Erstellungsdatum
(absteigend) sortiert. Jeder Eintrag enthält:
- `upvoteCount`: Anzahl der Upvotes
- `commentCount`: Anzahl der Kommentare
- `hasVoted`: Ob der aktuelle Nutzer bereits gevotet hat

## Vorschlag löschen

```
DELETE /feedback/suggestions/{id}
→ 204
```

### Löschberechtigung

| Bedingung | Erlaubt? |
|-----------|----------|
| Eigentümer + <3 Upvotes | Ja |
| Eigentümer + ≥3 Upvotes | Nein |
| Admin | Ja (immer) |
| Fremder Nutzer | Nein |

## Upvotes

Jeder Nutzer kann pro Vorschlag genau einmal upvoten. Das Setzen eines
bereits vorhandenen Upvotes gibt `409 Conflict` zurück.

```
POST /feedback/suggestions/{id}/vote → 204
DELETE /feedback/suggestions/{id}/vote → 204
```

## Status ändern (Admin)

```
PUT /feedback/suggestions/{id}/status
Body: { "status": "planned" }
→ 204
```

Nur Administratoren können den Status ändern. Ungültige Status-Werte
geben `400 Bad Request` zurück.

## Kommentare

Nutzer können zu jedem Funktionsvorschlag Kommentare verfassen und aufeinander
antworten. Kommentare werden als verschachtelte Baumstruktur zurückgegeben.

### Kommentare abrufen

```
GET /feedback/suggestions/{id}/comments
→ 200 { "data": [...], "meta": { "total": 5 } }
```

Die Kommentare werden als Baumstruktur zurückgegeben:
- Top-Level-Kommentare sind chronologisch aufsteigend sortiert
- Antworten werden unter ihrem Elternkommentar in `children` geschachtelt
- `text` ist `null` bei gelöschten Kommentaren, die noch Antworten haben

### Kommentar erstellen

```
POST /feedback/suggestions/{id}/comments
Body: { "text": "Das wäre wirklich hilfreich!", "parentId": null }
→ 201 { "data": { "id": "...", "text": "Das wäre wirklich hilfreich!", ... } }
```

| Feld | Typ | Pflicht | Beschreibung |
|------|-----|---------|-------------|
| `text` | string | Ja | Kommentartext |
| `parentId` | string (UUID) | Nein | ID des übergeordneten Kommentars (`null` = Top-Level) |

### Kommentar bearbeiten

```
PUT /feedback/suggestions/{id}/comments/{commentId}
Body: { "text": "Bearbeiteter Kommentar" }
→ 200 { "data": { ... } }
```

**Bedingungen:**
- Nur der Eigentümer darf bearbeiten
- Max. 10 Minuten nach Erstellung
- `text` darf nicht leer sein

### Kommentar löschen

```
DELETE /feedback/suggestions/{id}/comments/{commentId}
→ 204
```

**Verhalten:**
- **Hat Antworten:** Soft-Delete — `text` wird auf `NULL` gesetzt, Struktur bleibt erhalten
- **Keine Antworten:** Hard-Delete — Eintrag wird komplett entfernt
- Leere Eltern-Kommentare werden rekursiv mitgelöscht

**Löschberechtigung:**
- Eigentümer des Kommentars
- Administratoren

### Hinweis: Kaskaden-Löschen

Wenn ein Funktionsvorschlag gelöscht wird, werden automatisch **alle**
zugehörigen Kommentare mitgelöscht.
