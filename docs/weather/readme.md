# Wetter-Orte (Weather Locations)

Gespeicherte Wetter-Orte pro Benutzer, synchronisiert zwischen Geräten.

## Authentifizierung

Alle Endpunkte erfordern einen gültigen **Access-Token (JWT)** im `Authorization`-Header:

```
Authorization: Bearer <access_token>
```

## Endpunkte

### Alle gespeicherten Orte abrufen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/me/weather-locations` | Alle gespeicherten Wetter-Orte des Benutzers |

Gibt eine sortierte Liste aller gespeicherten Orte zurück (sortiert nach `sortOrder`).

### Ort hinzufügen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/user/me/weather-locations` | Neuen Wetter-Ort hinzufügen |

**Request Body:**

```json
{
  "name": "Berlin",
  "slug": "berlin",
  "lat": 52.52,
  "lon": 13.405,
  "source": "infranode"
}
```

| Feld | Typ | Pflicht | Beschreibung |
|------|-----|---------|--------------|
| `name` | string | ja | Anzeigename (max. 255 Zeichen) |
| `slug` | string | nein | InfraNode City-Slug (optional, alternativ zu lat/lon) |
| `lat` | float | ja* | Breitengrad (*entweder slug oder lat+lon) |
| `lon` | float | ja* | Längengrad |
| `source` | string | nein | Datenquelle (Standard: `nominatim`) |

**Limits:** Maximal 5 Orte pro Benutzer.

**Response:** `201` mit dem erstellten Ort.

### Alle Orte ersetzen (Full Sync)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PUT` | `/user/me/weather-locations` | Alle Orte auf einmal ersetzen |

**Request Body:**

```json
{
  "locations": [
    { "name": "Berlin", "slug": "berlin", "lat": 52.52, "lon": 13.405 },
    { "name": "Hamburg", "slug": "hamburg", "lat": 53.55, "lon": 9.99 }
  ]
}
```

Ersetzt die komplette Liste. Nützlich für Full-Sync beim Starten auf einem neuen Gerät.

**Limits:** Maximal 5 Orte.

**Response:** `200` mit der aktualisierten Liste.

### Ort aktualisieren

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PATCH` | `/user/me/weather-locations/{id}` | Einzelnen Ort aktualisieren |

**Request Body:** Beliebige Kombination der Felder (`name`, `slug`, `lat`, `lon`, `source`, `sortOrder`).

**Response:** `200` mit dem aktualisierten Ort.

### Ort löschen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `DELETE` | `/user/me/weather-locations/{id}` | Gespeicherten Ort entfernen |

**Response:** `204` ohne Inhalt.

## Fehlercodes

| Code | Beschreibung |
|------|-------------|
| `max_locations_reached` | Maximale Anzahl (5) erreicht |
| `location_not_found` | Ort nicht gefunden oder gehört nicht dem Benutzer |
| `invalid_request` | Request Body ungültig |
| `invalid_name` | Name fehlt oder ist zu lang |
| `invalid_source` | Source fehlt oder ist zu lang |
| `invalid_location` | Eintrag in der locations-Array ungültig |

## Datenbanktabelle

**`UserWeatherLocation`** – Gespeicherte Wetter-Orte pro Benutzer

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `id` | varchar(191) | UUID (Primary Key) |
| `userId` | varchar(191) | Referenz auf User |
| `name` | varchar(255) | Anzeigename |
| `slug` | varchar(191) | InfraNode City-Slug (nullable) |
| `lat` | double | Breitengrad |
| `lon` | double | Längengrad |
| `source` | varchar(50) | Datenquelle |
| `sortOrder` | tinyint | Sortierungsreihenfolge |
| `createdAt` | datetime(3) | Erstellungszeitpunkt |
| `updatedAt` | datetime(3) | Letzte Aktualisierung |
