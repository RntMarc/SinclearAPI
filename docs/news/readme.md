# Aktuell (News)

Die News-Funktion aggregiert Artikel aus RSS-Quellen und erlaubt das
Upvoten/Liken durch Nutzer. Artikel werden nur dann in der Datenbank
gespeichert, wenn sie mindestens einen Upvote erhalten haben.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `NewsArticle` | Gespeicherte Artikel (nur bei ≥1 Upvote) |
| `NewsUpvote` | Upvotes von Nutzern auf Artikel |
| `RssSource` | Konfigurierte RSS-Quellen |

## Storage-Strategie

1. Der Client zeigt dem Nutzer aktuelle Artikel direkt aus den RSS-Feeds an.
2. Upvotet der Nutzer einen Artikel, sendet der Client die Artikeldaten an die API.
3. Die API prüft, ob der Artikel bereits existiert (anhand der URL) und legt ihn
   ggf. neu an. Danach wird der Upvote gespeichert.
4. Einmal gespeicherte Artikel bleiben dauerhaft in der Datenbank – auch wenn sie
   später aus der RSS-Quelle verschwinden.

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/news/articles` | JWT | Paginierte Artikelliste (optional `?sourceName=`) |
| `GET` | `/news/articles/votes` | JWT | Eigene Upvotes (paginiert) |
| `POST` | `/news/articles/votes` | JWT | Artikel upvoten (legt Artikel ggf. an) |
| `DELETE` | `/news/articles/votes` | JWT | Upvote entfernen |
| `GET` | `/news/articles/archive` | JWT | Artikel ≥7 Tage alt mit mind. 1 Upvote |
| `GET` | `/news/articles/{id}/vote` | JWT | Vote-Status prüfen |
| `GET` | `/news/sources` | JWT | RSS-Quellen auflisten |

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
- `sourceIcon` ist optional (kann aus der RssSource-Konfiguration stammen).

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

1. Beim Laden der Seite `GET /news/articles` aufrufen → Artikel-Karten rendern.
2. Parallel (oder zwischengespeichert) `GET /news/articles/votes?limit=999` aufrufen
   → Liste der eigenen Upvotes abrufen → Herz-Icons entsprechend setzen.
3. Upvote-Button → `POST /news/articles/votes` → UI aktualisieren.
4. Remove-Vote → `DELETE /news/articles/votes` → UI aktualisieren.
5. "Archiv"-Tab → `GET /news/articles/archive` aufrufen.
