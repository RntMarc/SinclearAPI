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

### Öffentliche Endpunkte (ohne Authentifizierung)

Lesende Grunddaten sind über dedizierte öffentliche Endpunkte ohne Login erreichbar.
Alle privaten Endpunkte (siehe unten) erfordern ein gültiges JWT.

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| `GET` | `/public/recipes` | Rezepte auflisten (Seiten, Suche, Sortierung) |
| `GET` | `/public/recipes/{id}` | Rezept-Details abrufen |
| `GET` | `/html/public/recipe?id={id}` | Rezept als reine HTML-Seite für externe Parser (z.B. Bring) |

> **Anonymisierung:** Ohne Token werden `creatorId`, `creatorDisplayName` und
> `creatorImage` ausgeblendet und `isBookmarked` ist immer `false`.
> Wird ein gültiges JWT im Bearer-Header mitgesendet (die Endpunkte nutzen
> `auth.optional`), werden alle Felder geliefert.

### HTML-Rezeptseite für externe Parser (Bring & Co.)

Der Flutter-Client rendert Rezeptinhalte dynamisch im Canvas – für externe
Dienste, die eine URL öffnen und den HTML-Code parsen (z.B. die
Einkaufslisten-App **Bring**), ist deshalb eine statische HTML-Variante
notwendig:

```
GET /html/public/recipe?id=REZEPT_ID
```

**Beispiel (Produktion):**
```
https://sinclear.de/api/v2/html/public/recipe?id=550e8400-e29b-41d4-a716-446655440000
```

**Eigenschaften:**
- Reines, ungestyltes und semantisches HTML – ohne JavaScript
- Zutatenliste als `<ul>`, Zubereitungsschritte als `<ol>`
- Rezeptbild als `data:`-URI (`<img>`), nur wenn vorhanden
- Schema.org-Struktur als JSON-LD (`application/ld+json`) mit `@type: Recipe`
  (name, description, recipeIngredient, recipeInstructions, image, recipeYield,
  aggregateRating, datePublished) – Standard für automatische Extraktion
- Liefert exakt dieselben Daten wie `GET /public/recipes/{id}`
  (gleicher Service-Pfad inkl. Anonymisierung): ohne Token werden
  `creatorId`, `creatorDisplayName`, `creatorImage` nicht ausgegeben
- `Content-Type: text/html; charset=utf-8`

**Fehlerfälle:** fehlender `id`-Parameter → `400`, unbekannte Rezept-ID → `404`
(beide als einfache HTML-Fehlerseite).

### Rezepte auflisten (privat)

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

**Portionen (`servings`):**
`servings` ist optional (Standard: 4) und muss eine ganze Zahl zwischen 1 und
127 sein (entspricht der DB-Spalte `TINYINT`). Größere oder nicht ganzzahlige
Werte werden mit `invalid_servings` (HTTP 400) abgelehnt.

**Bild entfernen:**
```json
{
  "image": null
}
```

**Fehlercodes:**

| Code | Beschreibung |
|------|-------------|
| `invalid_servings` | Ungültige Portionsanzahl (ganzzahlig, 1–127) |
| `invalid_unit` | Ungültige Mengeneinheit (erlaubte Werte siehe unten) |
| `invalid_image` | Ungültiges Bild oder leerer String |
| `invalid_image_encoding` | Base64-Dekodierung fehlgeschlagen |
| `image_too_large` | Dateigröße überschreitet 200 KB |
| `invalid_image_format` | Datei ist kein gültiges Bild |
| `unsupported_image_format` | Format nicht erlaubt (nur JPEG, PNG, WebP) |
| `image_dimensions_too_large` | Abmessungen überschreiten 1000x1000 Pixel |

### Rezept-Details abrufen (privat)

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
| Rezept ansehen (privat) | Jeder authentifizierte Nutzer |
| Rezept ansehen (öffentlich) | `/public/recipes`, `/public/recipes/{id}` ohne Login, anonymisiert |
| Bewertung abgeben | Jeder authentifizierte Nutzer |
| Bewertung bearbeiten | Eigentümer der Bewertung |
| Bewertung löschen | Eigentümer der Bewertung oder Administrator |
| Bewertungen ansehen | Jeder authentifizierte Nutzer (privat) |

## Öffentlicher Zugriff (ohne Authentifizierung)

Die privaten lesenden Endpunkte (`GET /recipes`, `GET /recipes/{id}`,
`GET /recipes/{id}/reviews`) erfordern ein gültiges JWT.

Für Gäste ohne Login stehen die öffentlichen Endpunkte `GET /public/recipes` und
`GET /public/recipes/{id}` bereit. Sie nutzen die `auth.optional` Middleware:
Wenn ein gültiger JWT übergeben wird, werden alle Felder zurückgegeben.
Ohne Token werden folgende sensible Felder ausgeblendet:

- **Rezepte:** `creatorId`, `creatorDisplayName`, `creatorImage` werden nicht zurückgegeben
- **Detail-Ansicht:** `isBookmarked` ist immer `false` für anonyme Nutzer

Dies schützt die Privatsphäre der Nutzer, während die Inhalte (Rezepte, Zutaten,
Schritte, Durchschnittsbewertungen) für alle sichtbar bleiben. Bewertungen sind
nur für angemeldete Nutzer einsehbar.

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

## Mengeneinheiten

Das Feld `unit` einer Zutat ist ein Enum. **Kanonisch ist ausschließlich die
kleingeschriebene Schreibweise** (z.B. `tl`, `el`, `stk`), die alle Clients
senden und anzeigen müssen. Ungültige Werte werden mit dem Fehlercode
`invalid_unit` (HTTP 400) abgelehnt.

| Wert | Beschreibung |
|------|-------------|
| `g` | Gramm |
| `kg` | Kilogramm |
| `ml` | Milliliter |
| `l` | Liter |
| `tl` | Teelöffel |
| `el` | Esslöffel |
| `prise` | Prise |
| `stk` | Stück |
| `bund` | Bund |
| `zehe` | Zehe |
| `scheibe` | Scheibe |
| `tasse` | Tasse |
| `dose` | Dose |
| `packung` | Packung |
| `tropfen` | Tropfen |
