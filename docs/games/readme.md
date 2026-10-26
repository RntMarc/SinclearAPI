# Spiele-Statistiken

Nutzer verknüpfen ihre Accounts für verschiedene Spiele und rufen die zugehörigen
Statistiken ab. Die erste Integration ist **Deadlock** (Valve) über die
inoffizielle [Deadlock API](https://api.deadlock-api.com/docs). Das Grundgerüst
ist so aufgebaut, dass weitere Spiele mit eigenen APIs und eigenen Statistiken
ergänzt werden können, ohne die gemeinsame Struktur zu ändern.

## Überblick

| Aspekt | Verhalten |
|--------|-----------|
| Verknüpfung | Nutzer hinterlegen die `SteamID3` (Account-ID) ihres Spiels |
| Datenhaltung | Nur Profil-/Identitätsdaten in der DB; **Statistiken nur im Cache** |
| Aktualisierung | Cronjob fragt die Spiele-API stündlich ab und überschreibt den Cache |
| Abruf | Client liest Einzel- oder Batch-Statistiken aus dem Cache |
| Cache-Meta | Endpoint liefert den Zeitpunkt der letzten/nächsten Cache-Erneuerung |
| Vergleich/Scoreboard | Ausschließlich zwischen Beyond-Nutzern, die in der DB liegen |
| Sichtbarkeit | Statistiken sind öffentlich (Endpoint erfordert dennoch ein JWT) |

## Authentifizierung

Alle Endpoints erfordern ein gültiges JWT im
`Authorization: Bearer <token>`-Header.

## Endpoints

### Eigene verknüpfte Accounts

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/user/me/games` | Eigene verknüpfte Spiele-Accounts auflisten |
| `PUT` | `/user/me/games/{game}` | Account verknüpfen/ersetzen (Body: `{ "accountId": "179673173" }`) |
| `DELETE` | `/user/me/games/{game}` | Verknüpfung lösen |

Beim Verknüpfen wird die Account-ID validiert, das Profil (Name/Avatar) über die
Spiele-API aufgelöst und in `GameAccount` gespeichert. Eine ungültige oder nicht
auflösbare ID wird abgelehnt (`400 invalid_account_id` bzw.
`400 account_not_found`). Ein unbekanntes Spiel liefert `404 unknown_game`.
Ist die Spiele-API vorübergehend nicht verfügbar (Rate-Limit, Load Shedding,
Netzwerkfehler), antwortet der Endpoint mit `503 game_api_unavailable`.

### Verknüpfte Accounts anderer Beyond-Nutzer

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/user/{userId}/games` | Öffentliche Liste der verknüpften Accounts (inkl. `accountId`, Name, Avatar) |

### Statistiken

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/games/{game}/stats/{userId}` | Einzelprofil eines Beyond-Nutzers (aus Cache) |
| `GET` | `/games/{game}/stats?userIds=a,b,c` | Batch für mehrere Beyond-Nutzer |
| `GET` | `/games/{game}/cache-meta` | Zeitpunkt der Cache-Erneuerung |

Hinweise:

- Statische Routen (`cache-meta`) sind vor parametrisierten Routen registriert.
- `{game}` wird gegen die `GameRegistry` validiert; unbekannte Spiele liefern
  `404 unknown_game`.
- Die Batch-Antwort enthält nur Nutzer, die in der DB existieren und einen
  verknüpften Account für das Spiel besitzen. Fremde Steam-Nutzer oder
  Match-Teilnehmer werden nicht aufgelöst.
- Die Statistik-Antwort enthält Statistiken und `accountId`; Anzeigename/Avatar
  kommen aus dem Profil-Endpoint (`/user/{userId}/games`), um Statistik- und
  Profildaten sauber zu trennen.
- `stats` ist `null`, solange der Cronjob den Cache für den Account noch nicht
  gefüllt hat.

### Beispiel: Cache-Meta

```json
{
  "game": "deadlock",
  "lastRefreshedAt": "2026-10-25T14:00:00Z",
  "nextRefreshAt": "2026-10-25T15:00:00Z",
  "intervalSeconds": 3600
}
```

### Beispiel: Einzel-Statistik

```json
{
  "data": {
    "game": "deadlock",
    "userId": "0199…",
    "accountId": "179673173",
    "stats": {
      "accountId": "179673173",
      "rank": { "badge": 12, "rank": 1, "subrank": 2, "lastMatch": null },
      "heroStats": [],
      "matchHistory": [],
      "syncedAt": "2026-10-25T14:00:00Z"
    },
    "lastRefreshedAt": "2026-10-25T14:00:00Z",
    "nextRefreshAt": "2026-10-25T15:00:00Z"
  }
}
```

## Datenmodell

**Tabelle `GameAccount`** (Migration
`database/migrations/20261025120000_create_game_account.sql`):

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | varchar(191) | Primärschlüssel (UUIDv7) |
| `userId` | varchar(191) | Beyond-Nutzer-ID |
| `game` | varchar(50) | Spiel-Slug (z.B. `deadlock`) |
| `accountId` | varchar(191) | Primärer Identifier der Spiele-API (Deadlock: `SteamID3`) |
| `displayName` | varchar(191) | Steam-Anzeigename |
| `avatarUrl` | varchar(1024) | Steam-Avatar |
| `profile` | JSON | Spielspezifische Zusatzdaten, z.B. `{"steamId64": "…"}` |
| `lastSyncedAt` | datetime(3) | Zeitpunkt der letzten Statistik-Synchronisierung |
| `createdAt` / `updatedAt` | datetime(3) | Zeitstempel |

`UNIQUE (userId, game)` stellt sicher, dass ein Nutzer pro Spiel genau einen
Account verknüpft. `KEY (game, accountId)` beschleunigt die Batch-Suche.

In der DB liegen ausschließlich Profil-/Identitätsdaten. Die **Statistiken**
werden nicht in der DB gespeichert, sondern im Dateisystem gecacht.

## Architektur

```
src/Services/Games/
  GameStatsIntegrationInterface.php   // Vertrag für Spiele-Integrationen
  GameRegistry.php                    // game-Slug → Integration
  GameStatsCache.php                  // Dateisystem-Cache (Statistiken + Aux-Assets)
  Deadlock/
    DeadlockClient.php                // schlanker Guzzle-8-HTTP-Client
    DeadlockIntegration.php           // implementiert GameStatsIntegrationInterface
    DeadlockApiException.php          // Fehler inkl. Retry-After

src/Repository/GameAccountRepository.php
src/Services/GameAccountService.php
src/Services/GameStatsService.php     // Orchestrierung (Cache lesen, Meta, Koordination)
src/Security/Policy/GameAccountPolicy.php
src/Controllers/GameAccountController.php
src/Controllers/GameStatsController.php
src/Services/Cron/Tasks/GameStatsRefreshTask.php
```

`GameStatsIntegrationInterface` legt fest:

- `refreshAll(array $accounts): void` — Cache für alle Accounts aktualisieren
- `getCachedStats(string $accountId): ?array` — Statistiken aus dem Cache lesen
- `getCacheMeta(): array` — Zeitpunkt der letzten/nächsten Erneuerung
- `resolveProfile(string $accountId): array` — Profil (Name/Avatar) auflösen

Ein neues Spiel ergänzt nur eine neue Integration und einen
`GameRegistry`-Eintrag; Controller, Cache und Endpoints bleiben unverändert.

## Deadlock-Client

| Methode | Deadlock-Endpoint | Zweck |
|---------|-------------------|-------|
| `rankBatch($accountIds)` | `GET /v1/players/rank` | Rang/Badge (Batch) |
| `heroStats($accountIds)` | `GET /v1/players/hero-stats` | Helden-Statistiken (Batch) |
| `matchHistory($accountId)` | `GET /v1/players/{id}/match-history` | Letzte Matches |
| `heroAssets()` | `GET /v1/assets/heroes` | Helden-Namen und Bild-URLs (Aux-Cache, 24 h) |
| `rankAssets()` | `GET /v1/assets/ranks` | Rang-Namen und Bild-URLs (Aux-Cache, 24 h) |
| `steamProfiles($accountIds)` | `GET /v1/players/steam` | Name/Avatar (beim Verknüpfen mit `refresh=true`) |

Der Client nutzt Guzzle 8, den User-Agent `SinclearBeyondAPI/2.0`, ein Timeout
von 15 Sekunden und einen optionalen API-Key-Header (`X-API-KEY`). Antwortet die
API mit `503 Service Unavailable` (Load Shedding), wird der
`Retry-After`-Header ausgewertet und der Vorgang beim nächsten Cron-Lauf
wiederholt.

### Normalisierte Deadlock-Statistiken

Pro Account wird folgende Struktur gecacht:

```json
{
  "accountId": "179673173",
  "rank": {
    "badge": 12,
    "rank": 1,
    "subrank": 2,
    "name": "Initiate",
    "iconUrl": "https://assets-bucket.deadlock-api.com/assets-api-res/images/ranks/rank01_lg.png",
    "lastMatch": { "matchId": "…", "startTime": "…" }
  },
  "heroStats": [
    {
      "heroId": 2,
      "heroName": "Seven",
      "heroIconUrl": "https://assets-bucket.deadlock-api.com/assets-api-res/images/heroes/gigawatt_sm.png",
      "matchesPlayed": 2,
      "wins": 0,
      "losses": 2,
      "winRate": 0.0,
      "lastPlayed": "2026-10-18T20:14:56Z",
      "timePlayedSeconds": 3118,
      "endingLevel": 21.5,
      "kills": 3,
      "deaths": 17,
      "assists": 8,
      "killsPerMin": 0.0577,
      "deathsPerMin": 0.3271,
      "assistsPerMin": 0.1539,
      "networthPerMin": 771.82,
      "lastHitsPerMin": 3.27,
      "deniesPerMin": 0.0,
      "damagePerMin": 401.47,
      "damageTakenPerMin": 804.96,
      "accuracy": 0.4893
    }
  ],
  "matchHistory": [
    {
      "matchId": "124029819",
      "heroId": 87,
      "heroName": "Nurse Harrow",
      "heroIconUrl": "https://assets-bucket.deadlock-api.com/assets-api-res/images/heroes/nurse_sm.png",
      "heroLevel": 36,
      "startTime": "2026-10-24T20:02:14Z",
      "gameMode": 1,
      "matchMode": 1,
      "playerTeam": 0,
      "kills": 7,
      "deaths": 4,
      "assists": 23,
      "netWorth": 58231,
      "lastHits": 185,
      "denies": 1,
      "matchDurationSeconds": 2852,
      "matchResult": 0,
      "playerMatchOutcome": 1,
      "abandonedTimeSeconds": 0
    }
  ],
  "syncedAt": "2026-10-25T14:00:00Z"
}
```

`matchHistory` enthält maximal 20 Matches, absteigend nach `startTime`.

`rank.name`/`rank.iconUrl` sowie `heroName`/`heroIconUrl` werden aus den
Asset-Endpunkten der Deadlock API angereichert (Rang-Tier bzw. Helden-ID).
Ist der Asset-Abruf nicht verfügbar, werden die Statistiken trotzdem
geschrieben und die Asset-Felder bleiben `null`; Clients können dann auf
lokale Fallback-Namen (z. B. `Held #87`) zurückgreifen.

## Caching

```
var/cache/game-stats/
  deadlock/
    _meta.json              // { lastRefreshedAt, nextRefreshAt, intervalSeconds }
    _heroes.json            // { "7": { name, iconUrl }, … }  (TTL 24 h)
    _ranks.json             // { "1": { name, iconUrl }, … }  (TTL 24 h)
    {accountId}.json        // normalisierte Statistiken (rank, heroStats, matchHistory)
```

- TTL standardmäßig 3600 Sekunden (`GAMES_CACHE_TTL`); der Cronjob überschreibt
  den Cache im selben Intervall, damit `nextRefreshAt` und der Cron-Takt
  übereinstimmen.
- `_meta.json` speist den Cache-Meta-Endpoint.
- `_heroes.json` und `_ranks.json` sind Aux-Caches für Stammdaten mit eigener
  TTL von 24 Stunden. Die Helden-Antwort ist rund 2 MB groß und wird deshalb
  nicht stündlich geladen. `clean()` lässt Dateien mit führendem Unterstrich
  unangetastet.
- Einträge, die älter als die TTL sind, werden nicht mehr ausgeliefert; die
  Cache-Metadaten zeigen den Zeitpunkt der letzten Erneuerung.
- `var/` liegt außerhalb von `public/` und ist damit nicht öffentlich erreichbar
  (siehe `.htaccess`).

## Cronjob

`src/Services/Cron/Tasks/GameStatsRefreshTask.php` (Intervall = `GAMES_CACHE_TTL`,
Default 3600 Sekunden), registriert in `bin/cron.php`:

1. Alle `GameAccount`-Einträge je Spiel laden.
2. Für Deadlock: Helden- und Rang-Assets cache-first laden (Aux-Cache, TTL 24 h)
   und daraus die Anzeigenamen/Bild-URLs aufbauen.
3. Batch-Rang, Batch-Helden-Statistiken und Match-History abrufen.
4. Normalisieren und pro Account nach `var/cache/game-stats/{game}/{accountId}.json`
   schreiben; `_meta.json` aktualisieren.
5. Bei `503` (Load Shedding) abbrechen und beim nächsten Lauf wiederholen.
   Andere Fehler der Rang-/Helden-Batches werden protokolliert, ohne die übrigen
   Daten zu verwerfen. Schlägt der Asset-Abruf fehl, werden die Statistiken
   trotzdem geschrieben (Asset-Felder `null`).

Siehe auch [CRON.md](../CRON.md).

## Konfiguration

`config/settings.php`:

```php
$settings['games'] = [
    'deadlock_base_url' => $_ENV['DEADLOCK_BASE_URL'] ?? 'https://api.deadlock-api.com',
    'deadlock_api_key'  => $_ENV['DEADLOCK_API_KEY'] ?? '',
    'cache_ttl'         => (int)($_ENV['GAMES_CACHE_TTL'] ?? 3600),
];
```

`.env.example`:

```dotenv
DEADLOCK_BASE_URL=https://api.deadlock-api.com
DEADLOCK_API_KEY=
GAMES_CACHE_TTL=3600
```

Der API-Key ist optional. Ist er leer, werden die öffentlichen Rate-Limits
genutzt.

## Weitere Spiele ergänzen

1. Neue Integration unter `src/Services/Games/<Game>/` implementieren
   (`GameStatsIntegrationInterface`).
2. Integration in der `GameRegistry` unter dem Spiel-Slug registrieren
   (`config/dependencies.php`).
3. Eigenen Cache-Ordner `var/cache/game-stats/<game>/` verwenden.
4. Normalisierte Statistikstruktur dokumentieren.

Die gemeinsamen Endpoints, der Cron-Rahmen und das `GameAccount`-Schema bleiben
unverändert.

## Nicht-Ziele (v1)

- Keine fremden Steam-Nutzer oder Match-Teilnehmer.
- Kein globaler öffentlicher Ranglisten-Endpoint; Vergleiche nur zwischen
  Beyond-Nutzern.
- Keine Notification-Typen und keine Admin-Bearbeitungs-UI.
- API-Key ist vorbereitet, aber standardmäßig nicht aktiv.
