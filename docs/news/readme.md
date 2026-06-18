# Aktuell (News)

Die News-Funktion aggregiert Artikel aus RSS-Quellen und erlaubt das
Upvoten/Liken durch Nutzer. Artikel aus den RSS-Quellen werden live
bei jedem API-Call abgerufen und zusammen mit den gespeicherten
Artikeln aus der Datenbank ausgeliefert.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `NewsArticle` | Gespeicherte Artikel (nur bei ≥1 Upvote) |
| `NewsUpvote` | Upvotes von Nutzern auf Artikel |
| `RssSource` | Konfigurierte RSS-Quellen |

## Storage-Strategie (`GET /news/articles`)

1. `GET /news/articles` gibt **zwei separate Datensätze** zurück:
   - **`data`** – Artikel aus der Datenbank (entstanden durch Upvotes)
   - **`rss`** – aktuellste Artikel aus allen RSS-Quellen (live abgerufen)
2. Die **RSS-Artikel** werden bei jedem API-Call live von den
   konfigurierten Quellen abgerufen und via Guzzle + SimpleXML geparst.
3. Beide Datensätze enthalten nur Artikel der **letzten 7 Tage**.
4. Der Client ist für das Zusammenführen und Anzeigen beider Datensätze
   zuständig (z. B. nach Datum sortiert, mit Deduplizierung per URL).
5. Die Anzahl der abgerufenen RSS-Artikel pro Quelle wird durch das
   Feld `itemsPerPage` in `RssSource` gesteuert.
6. Upvotet der Nutzer einen Artikel, sendet der Client die Artikeldaten
   an die API (`POST /news/articles/votes`). Die API prüft, ob der
   Artikel bereits existiert (anhand der URL) und legt ihn ggf. neu an.
   Danach wird der Upvote gespeichert.
7. Einmal gespeicherte Artikel bleiben dauerhaft in der Datenbank –
   auch wenn sie später aus der RSS-Quelle verschwinden.

## Storage-Strategie (`GET /news/articles/archive`)

- Gibt alle Artikel aus der Datenbank zurück, die **älter als 7 Tage**
  sind und mindestens einen Upvote besitzen (global, nicht
  nutzerbezogen). Archivierte Artikel haben keinen RSS-Anteil.

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/news/articles` | JWT | Artikelliste aus DB (`data`) + RSS (`rss`), paginiert, ≤7 Tage, optional `?sourceName=` |
| `GET` | `/news/articles/votes` | JWT | Eigene Upvotes (paginiert) |
| `POST` | `/news/articles/votes` | JWT | Artikel upvoten (legt Artikel ggf. an) |
| `DELETE` | `/news/articles/votes` | JWT | Upvote entfernen |
| `GET` | `/news/articles/archive` | JWT | Artikel ≥7 Tage alt mit mind. 1 Upvote |
| `GET` | `/news/articles/{id}/vote` | JWT | Vote-Status prüfen |
| `GET` | `/news/sources` | JWT | RSS-Quellen auflisten |

### Antwortformat `GET /news/articles`

```json
{
  "data": [
    {
      "id": "018f0a1b-2c3d-4e5f-6a7b-8c9d0e1f2a3b",
      "title": "Artikel-Titel",
      "url": "https://example.com/article",
      "sourceName": "Heise Online",
      "sourceIcon": null,
      "savedAt": "2026-06-17T12:00:00.000Z"
    }
  ],
  "rss": [
    {
      "title": "Aktuelle Nachricht",
      "url": "https://example.com/aktuell",
      "sourceName": "Heise Online",
      "sourceIcon": null,
      "publishedAt": "2026-06-17T14:00:00.000Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 5,
    "totalPages": 1,
    "rssCount": 15
  }
}
```

## Endpunkt-Details

### POST /news/articles/votes

Erzeugt einen Upvote und ggf. den Artikel. Der Body muss folgende Felder enthalten:

```json
{
  "url": "https://example.com/article",
  "title": "Artikel-Titel",
  "sourceName": "Heise Online",
  "sourceIcon": "https://example.com/icon.png"
}
```

- Falls ein Artikel mit derselben URL bereits existiert, wird nur der Upvote angelegt.
- Der `sourceName` muss exakt mit dem Namen einer `RssSource` übereinstimmen.
- `sourceIcon` ist optional (kann aus der Client-Konfiguration stammen).

### DELETE /news/articles/votes

```json
{
  "articleId": "uuid-des-artikels"
}
```

### GET /news/articles/{id}/vote

Prüft, ob der angemeldete Nutzer diesen Artikel geupvotet hat.

### GET /news/articles/archive

Gibt alle Artikel zurück, die älter als 7 Tage sind und mindestens einen Upvote
besitzen (global, nicht nutzerbezogen).

## Client-Integration (Empfohlen)

1. Beim Laden der Seite `GET /news/articles` aufrufen → `data` (DB-Artikel)
   und `rss` (RSS-Artikel) rendern. Beide Datensätze können nach `savedAt` /
   `publishedAt` sortiert und per URL dedupliziert werden.
2. Parallel (oder zwischengespeichert) `GET /news/articles/votes?limit=999` aufrufen
   → Liste der eigenen Upvotes abrufen → Herz-Icons entsprechend setzen.
3. Upvote-Button → `POST /news/articles/votes` → UI aktualisieren.
4. Remove-Vote → `DELETE /news/articles/votes` → UI aktualisieren.
5. "Archiv"-Tab → `GET /news/articles/archive` aufrufen.
