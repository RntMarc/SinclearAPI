# Fotos (Unsplash)

Die Foto-Funktion zeigt öffentliche Unsplash-Bilder von Nutzern, die ihren
Unsplash-Nutzernamen in ihrem Profil hinterlegt haben. Die Bilder werden
serverseitig über die Unsplash-API abgerufen, gecacht und als flacher,
zeitlich absteigend sortierter Feed an den Client ausgeliefert.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben werden ausschließlich
> in UTC gespeichert und ausgegeben (`YYYY-MM-DD HH:MM:SS`, 24h, ohne
> Zeitzonenindikatoren). Clients konvertieren in die lokale Zeitzone.

## Voraussetzungen

- Der Nutzer hat in `SocialInfo.unsplashHandle` einen Unsplash-Nutzernamen
  hinterlegt (Profil bearbeiten → Soziale Netzwerke; `PUT /user/me/profile`).
- Die Sichtbarkeit dieses Handles steht in `UserPreferences.unsplashVisibility`:
  `0` = nur ich, `1` = alle (Standard), `2` = nur enge Freunde
  (`PUT /user/me/preferences` bzw. `PUT /user/me/visibility`).

Die Sichtbarkeitsprüfung erfolgt über die bestehende `UserPolicy::canView()`:
- Der Nutzer selbst sieht seine eigenen Fotos immer.
- `1` → alle authentifizierten Nutzer.
- `2` → nur Nutzer, die in `CloseFriend` als enger Freund des Autors stehen.
- `0` → niemand außer dem Autor.

## Unsplash-API & Caching

- Abruf über `GET {UNSPLASH_BASE_URL}/users/{username}/photos?order_by=latest&per_page={UNSPLASH_PER_USER_LIMIT}`.
- Autorisierung über `UNSPLASH_ACCESS_KEY` (Query-Parameter `client_id`).
- Da der Demo-Key nur 50 Anfragen/Stunde erlaubt, wird jede Nutzer-Antwort im
  generischen `ExternalDataCache` zwischengespeichert:
  - `data_type = unsplash_user_photos`
  - `location_key = <unsplash-username>` (klein geschrieben)
  - `source = unsplash`
  - TTL = `UNSPLASH_CACHE_TTL` (Standard 21600 s = 6 h)
- Ist ein Handle unbekannt (404), der Key fehlt oder Unsplash nicht erreichbar,
  wird der Nutzer **still übersprungen** und nichts gecacht — der Fehler wird
  beim nächsten Abruf erneut versucht. Der Feed liefert nie einen Gesamtfehler
  wegen eines einzelnen Nutzers.
- Der generische Cron-Task `CleanupExternalDataCacheTask` räumt abgelaufene
  Einträge mit auf.

### Konfiguration

| Variable | Standard | Beschreibung |
|---|---|---|
| `UNSPLASH_ACCESS_KEY` | (leer) | Unsplash Access Key (Demo oder Production) |
| `UNSPLASH_BASE_URL` | `https://api.unsplash.com` | Unsplash-API-Basis |
| `UNSPLASH_PER_USER_LIMIT` | `30` | Neueste Fotos pro Nutzer |
| `UNSPLASH_CACHE_TTL` | `21600` | Cache-Dauer in Sekunden (6 h) |

## Endpunkte (alle authentifiziert, Basis `/api/v2`)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/photos` | Foto-Feed: flache Liste sichtbarer Fotos, neueste zuerst, paginiert |
| `GET` | `/photos/user/{id}` | Fotos eines einzelnen Nutzers (sichtbarkeitsgeprüft) |

### Foto-Feed (`GET /photos`)

Query-Parameter (Codebase-Konvention):

| Parameter | Standard | Max | Beschreibung |
|-----------|----------|-----|--------------|
| `page` | `1` | — | Seite |
| `limit` | `30` | `100` | Einträge pro Seite |

Antwort:

```json
{
  "data": [
    {
      "id": "eV6YVEp8f2E",
      "thumb": "https://images.unsplash.com/photo-...&w=400",
      "regular": "https://images.unsplash.com/photo-...&w=1080",
      "width": 4000,
      "height": 3000,
      "createdAt": "2026-09-10 14:32:00",
      "photographer": {
        "name": "Jane Doe",
        "username": "janedoe",
        "url": "https://unsplash.com/@janedoe"
      },
      "author": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "displayName": "Marc",
        "avatar": "<base64 Profilbild oder null>"
      }
    }
  ],
  "meta": {
    "page": 1,
    "limit": 30,
    "total": 45,
    "totalPages": 2
  }
}
```

- Die Liste ist nach `createdAt` **absteigend** sortiert (neuestes Foto oben).
- Der Feed enthält ausschließlich Fotos von Nutzern, die der Anfragende sehen darf.
- Fotos werden pro Nutzer auf die neuesten `UNSPLASH_PER_USER_LIMIT` begrenzt,
  bevor die Gesamtliste zusammengeführt und paginiert wird.

### Fotos eines Nutzers (`GET /photos/user/{id}`)

Antwort wie oben mit `{"data": [ … ]}` (ungepaginiert, max.
`UNSPLASH_PER_USER_LIMIT` Fotos).

Fehlerantworten:
- `404` (`user_not_found`): Nutzer existiert nicht oder hat keinen Unsplash-Handle.
- `403` (`forbidden`): Der anfragende Nutzer darf die Fotos nicht sehen
  (Sichtbarkeit `0` oder `2` ohne enge Freundschaft).

## Attribution

Laut Unsplash-Richtlinien muss der Fotograf genannt werden. Der API liefert
deshalb pro Foto `photographer.name`, `photographer.username` und
`photographer.url` mit; der Client zeigt den Namen und verlinkt auf das
Unsplash-Profil.

## Datenbank-Tabellen

| Tabelle | Nutzung |
|---------|---------|
| `SocialInfo` | `unsplashHandle` (Unsplash-Nutzername) |
| `UserPreferences` | `unsplashVisibility` (0/1/2) |
| `CloseFriend` | Enger-Freunde-Beziehung für Sichtbarkeit `2` |
| `ExternalDataCache` | Gecachte Unsplash-Antworten (`source = unsplash`) |

Migration: `database/migrations/20260914120000_add_unsplash_cache_source.sql`
(erweitert den `source`-Enum um `unsplash`).
