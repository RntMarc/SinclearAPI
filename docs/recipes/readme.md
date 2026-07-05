# Rezepte (Recipes)

Die Recipe-Funktion ermöglicht es Nutzern, Rezepte zu erstellen, zu durchsuchen,
zu bewerten und als Lesezeichen zu speichern. Rezepte enthalten Zutaten und
Zubereitungsschritte, die direkt im API-Response verschachtelt zurückgegeben werden.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Das Format ist `YYYY-MM-DD HH:MM:SS` (24h, ohne Millisekunden, ohne Zeitzonenindikatoren). Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC vor dem Senden und von UTC in die lokale Zeitzone bei der Anzeige verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `Recipe` | Haupttabelle mit allen Rezeptdaten |
| `RecipeIngredient` | Zutaten für ein Rezept (Menge, Einheit, Name) |
| `RecipeStep` | Zubereitungsschritte (Kategorie, Beschreibung) |
| `RecipeReview` | Bewertungen (1-5 Sterne + optionale Anmerkung) |
| `RecipeBookmark` | Lesezeichen der Nutzer |

## Endpunkte

### Rezepte auflisten

```
GET /recipes?page=&limit=&search=&sort=
```

| Parameter | Typ | Beschreibung |
|-----------|-----|-------------|
| `page` | int | Seitennummer (Standard: 1) |
| `limit` | int | Einträge pro Seite (max. 100, Standard: 20) |
| `search` | string | Volltextsuche in Titel und Zutaten |
| `sort` | string | `created_asc`, `created_desc`, `rating_asc`, `rating_desc` |

### Rezept erstellen

```
POST /recipes
```

```json
{
  "title": "Käsekuchen",
  "description": "Ein leckerer Käsekuchen",
  "category": "backen",
  "dietaryTags": "vegetarisch",
  "image": "base64...",
  "servings": 12,
  "ingredients": [
    { "amount": 250, "unit": "g", "name": "Mehl", "order": 0 }
  ],
  "steps": [
    { "category": "vorbereitung", "description": "Backofen vorheizen", "order": 0 }
  ]
}
```

**Rezeptbild:**
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

### Rezept-Details abrufen

```
GET /recipes/{id}
```

Response enthält vollständige Details inkl. `ingredients`, `steps`, `avgRating`,
`ratingCount` und `isBookmarked`.

### Rezept aktualisieren

```
PATCH /recipes/{id}
```

Nur Eigentümer oder Administrator. Zutaten und Schritte werden komplett ersetzt,
wenn sie im Request-Body enthalten sind.

### Rezept löschen

```
DELETE /recipes/{id}
```

Löscht das Rezept und alle zugehörigen Zutaten, Schritte, Bewertungen und Lesezeichen.

## Lesezeichen

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| `GET` | `/recipes/{id}/bookmark` | Status abfragen |
| `POST` | `/recipes/{id}/bookmark` | Lesezeichen setzen |
| `DELETE` | `/recipes/{id}/bookmark` | Lesezeichen entfernen |
| `GET` | `/recipes/bookmarks` | Eigene Lesezeichen auflisten |

## Bewertungen

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| `GET` | `/recipes/{id}/reviews` | Bewertungen auflisten |
| `POST` | `/recipes/{id}/reviews` | Bewertung abgeben (1-5) |
| `PATCH` | `/recipes/{id}/reviews/{reviewId}` | Bewertung bearbeiten (nur Eigentümer) |
| `DELETE` | `/recipes/{id}/reviews/{reviewId}` | Bewertung löschen (Eigentümer/Admin) |

## Autorisierung

| Aktion | Berechtigung |
|--------|-------------|
| Rezept erstellen | Jeder authentifizierte Nutzer |
| Rezept bearbeiten | Eigentümer oder Administrator |
| Rezept löschen | Eigentümer oder Administrator |
| Rezept ansehen | Jeder authentifizierte Nutzer |
| Bewertung abgeben | Jeder authentifizierte Nutzer |
| Bewertung bearbeiten | Eigentümer der Bewertung |
| Bewertung löschen | Eigentümer der Bewertung oder Administrator |

## Kategorien

| Wert | Beschreibung |
|------|-------------|
| `vorspeisen` | Vorspeisen |
| `hauptgerichte` | Hauptgerichte |
| `desserts` | Desserts |
| `salate` | Salate |
| `suppen` | Suppen |
| `backen` | Backen |
| `fruehstueck` | Frühstück |
| `getraenke` | Getränke |
| `sonstiges` | Sonstiges |

## Schritt-Kategorien

| Wert | Beschreibung |
|------|-------------|
| `vorbereitung` | Vorbereitung |
| `hauptgang` | Hauptgang |
| `beilage` | Beilage |
| `garnierung` | Garnierung |
| `sonstiges` | Sonstiges |
