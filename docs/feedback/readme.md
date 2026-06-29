# Feedback (Funktionsvorschläge)

Die Feedback-Funktion ermöglicht es Nutzern, Funktionsvorschläge zu erstellen
und gegenseitig zu bewerten. Vorschläge werden nach Beliebtheit (Upvotes)
sortiert angezeigt.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `FeedbackSuggestion` | Funktionsvorschläge mit Titel, Beschreibung und Status |
| `FeedbackVote` | Upvotes von Nutzern für Vorschläge (1x pro Nutzer pro Vorschlag) |

## Status-Werte

| Status | Beschreibung |
|--------|-------------|
| `submitted` | Standard. Vorschlag eingereicht |
| `planned` | Wird in Planung aufgenommen |
| `next` | Nächstes Feature |
| `in_progress` | In Entwicklung |
| `done` | Umgesetzt |
| `cancelled` | Abgesagt |
| `rejected` | Abgelehnt |
| `later` | Später eventuell |

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/feedback/suggestions` | JWT | Alle Vorschläge (paginiert, sortiert nach Upvotes) |
| `POST` | `/feedback/suggestions` | JWT | Neuen Vorschlag erstellen |
| `DELETE` | `/feedback/suggestions/{id}` | JWT | Vorschlag löschen (Eigentümer + <3 Upvotes oder Admin) |
| `POST` | `/feedback/suggestions/{id}/vote` | JWT | Upvote abgeben (1x pro Nutzer) |
| `DELETE` | `/feedback/suggestions/{id}/vote` | JWT | Upvote zurückziehen |
| `PUT` | `/feedback/suggestions/{id}/status` | JWT + Admin | Status ändern (nur Admin) |

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
