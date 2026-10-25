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
| Sichtbarkeit | Statistiken sind öffentlich |

## Design-Entscheidungen

| Punkt | Entscheidung |
|-------|--------------|
| Deadlock-Zugriff | Eigener schlanker Guzzle-8-Client (kein Composer-Paket) |
| Identifier | `SteamID3`/Account-ID (z. B. `179673173`); `SteamID64` wird beim Verknüpfen abgeleitet |
| Statistiken-Cache | Dateisystem `var/cache/game-stats/` (Muster: `NominatimCache`) |
| Umfang v1 | Rang/Badge, Helden-Statistiken, letzte Matches |
| Steam-Profil (Name/Avatar) | In der DB persistiert (Teil der Profilverwaltung) |
| Datenmodell | Tabelle `GameAccount` |
| API-Key | Optional und konfigurierbar (Default: ohne Key) |
| Vergleich/Scoreboard | Nur bekannte Beyond-Nutzer, keine fremden Steam-Nutzer oder Match-Teilnehmer |

### Warum kein vorgefertigter PHP-Client?

Der offizielle PHP-Client (`deadlock-api/openapi-clients`) ist für dieses Projekt
nicht direkt nutzbar:

1. Er verlangt `guzzlehttp/guzzle: ^7.3`, das Projekt nutzt `^8.3` — Composer kann
   beide Constraints nicht gleichzeitig auflösen.
2. Das Paket liegt nicht auf Packagist; es ist ein Monorepo, dessen
   `composer.json` nur im Unterordner `php/` liegt.
3. Der `*@dev`-Constraint wäre nicht deterministisch.

Stattdessen implementieren wir einen schlanken Client auf Basis des vorhandenen
Guzzle 8. Benötigt werden nur vier Endpoints; die Deadlock-API ist ohnehin
versioniert, sodass Breaking Changes immer eine Codeanpassung erfordern.

## Datenmodell

**Tabelle `GameAccount`** (Migration in `database/migrations/`):

```sql
CREATE TABLE GameAccount (
  id            varchar(191)  NOT NULL,
  userId        varchar(191)  NOT NULL,
  game          varchar(50)   NOT NULL,            -- 'deadlock'
  accountId     varchar(191)  NOT NULL,            -- SteamID3 (Account-ID)
  displayName   varchar(191)  DEFAULT NULL,        -- Steam-Anzeigename
  avatarUrl     varchar(1024) DEFAULT NULL,        -- Steam-Avatar
  profile       JSON          DEFAULT NULL,        -- spielspezifisch, z.B. {"steamId64": "…"}
  lastSyncedAt  datetime(3)   DEFAULT NULL,
  createdAt     datetime(3)   NOT NULL,
  updatedAt     datetime(3)   NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY GameAccount_user_game_key (userId, game),
  KEY GameAccount_game_accountId_idx (game, accountId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `accountId` ist der primäre Identifier der jeweiligen Spiele-API
  (Deadlock: `SteamID3`).
- `profile` (JSON) nimmt spielspezifische Zusatzdaten auf, damit spätere Spiele
  keine Schemaänderung benötigen.
- In der DB liegen ausschließlich Profil-/Identitätsdaten. Die **Statistiken**
  werden nicht in der DB gespeichert, sondern im Dateisystem gecacht.

## Architektur

```
src/Services/Games/
  GameStatsIntegrationInterface.php   // Vertrag für Spiele-Integrationen
  GameRegistry.php                    // game-Slug → Integration
  Deadlock/
    DeadlockClient.php                // schlanker Guzzle-8-HTTP-Client
    DeadlockIntegration.php           // implementiert GameStatsIntegrationInterface

src/Repository/GameAccountRepository.php
src/Services/GameAccountService.php
src/Services/GameStatsService.php     // Orchestrierung (Cache lesen, Meta, Koordination)
src/Security/Policy/GameAccountPolicy.php
src/Controllers/GameAccountController.php
src/Controllers/GameStatsController.php
```

`GameStatsIntegrationInterface` legt fest:

- `refreshAll(array $accounts): void` — Cache für alle Accounts aktualisieren
- `getCachedStats(string $accountId): ?array` — Statistiken aus dem Cache lesen
- `getCacheMeta(): array` — Zeitpunkt der letzten/nächsten Erneuerung
- `resolveProfile(string $accountId): array` — Profil (Name/Avatar) auflösen

Ein neues Spiel ergänzt nur eine neue Integration und einen
`GameRegistry`-Eintrag; Controller, Cache und Endpoints bleiben unverändert.

### Deadlock-Client

| Methode | Deadlock-Endpoint | Zweck |
|---------|-------------------|-------|
| `rankBatch($accountIds)` | `GET /v1/players/rank` | Rang/Badge (Batch) |
| `heroStats($accountIds)` | `GET /v1/players/hero-stats` | Helden-Statistiken (Batch) |
| `matchHistory($accountId)` | `GET /v1/players/{id}/match-history` | Letzte Matches |
| `steamProfiles($accountIds)` | `GET /v1/players/steam` | Name/Avatar (beim Verknüpfen mit `refresh=true`) |

Der Client nutzt Guzzle 8, einen eigenen User-Agent
(`SinclearBeyondAPI/2.0`), ein Timeout und einen optionalen API-Key-Header.
Antwortet die API mit `503 Service Unavailable` (Load Shedding), wird der
`Retry-After`-Header respektiert und der Vorgang beim nächsten Cron-Lauf
wiederholt.

## Caching

```
var/cache/game-stats/
  deadlock/
    _meta.json              // { lastRefreshedAt, nextRefreshAt, intervalSeconds }
    {accountId}.json        // normalisierte Statistiken (rank, heroStats, matchHistory)
```

- TTL 3600 Sekunden; der Cronjob überschreibt den Cache stündlich.
- `_meta.json` speist den Cache-Meta-Endpoint.
- `var/` liegt außerhalb von `public/` und ist damit nicht öffentlich erreichbar.

## Endpoints

Alle Endpoints erfordern ein gültiges JWT im
`Authorization: Bearer <token>`-Header.

### Eigene verknüpfte Accounts

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/user/me/games` | Eigene verknüpfte Spiele-Accounts auflisten |
| `PUT` | `/user/me/games/{game}` | Account verknüpfen/ersetzen (Body: `{ "accountId": "179673173" }`) |
| `DELETE` | `/user/me/games/{game}` | Verknüpfung lösen |

Beim Verknüpfen wird die Account-ID validiert, das Profil (Name/Avatar) über die
Spiele-API aufgelöst und in `GameAccount` gespeichert. Eine ungültige oder nicht
auflösbare ID wird abgelehnt.

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

- Statische Routen (`cache-meta`) werden vor parametrisierten Routen registriert.
- `{game}` wird gegen die `GameRegistry` validiert; unbekannte Spiele liefern
  `400`/`404`.
- Die Batch-Antwort enthält nur Nutzer, die in der DB existieren und einen
  verknüpften Account für das Spiel besitzen. Fremde Steam-Nutzer oder
  Match-Teilnehmer werden nicht aufgelöst.
- Die Statistik-Antwort enthält Statistiken und `accountId`; Anzeigename/Avatar
  kommen aus dem Profil-Endpoint (`/user/{userId}/games`), um Statistik- und
  Profildaten sauber zu trennen.

### Beispiel: Cache-Meta

```json
{
  "game": "deadlock",
  "lastRefreshedAt": "2026-10-25T14:00:00Z",
  "nextRefreshAt": "2026-10-25T15:00:00Z",
  "intervalSeconds": 3600
}
```

## Cronjob

`src/Services/Cron/Tasks/GameStatsRefreshTask.php` (Intervall 3600 Sekunden),
registriert in `bin/cron.php`:

1. Alle `GameAccount`-Einträge je Spiel laden.
2. Für Deadlock: Batch-Rang, Batch-Helden-Statistiken und Match-History abrufen.
3. Normalisieren und pro Account nach `var/cache/game-stats/{game}/{accountId}.json`
   schreiben; `_meta.json` aktualisieren.
4. Bei `503` (Load Shedding) abbrechen und beim nächsten Lauf wiederholen.

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
2. Integration in der `GameRegistry` unter dem Spiel-Slug registrieren.
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

## Umsetzungsphasen

1. Migration `GameAccount` + `GameAccountRepository` + Policy.
2. `DeadlockClient`, `DeadlockIntegration`, `GameRegistry`, `GameStatsService`.
3. Cache-Layer (Muster `NominatimCache`).
4. Controller und Routen.
5. Cron-Task und Registrierung.
6. `openapi.yaml`, `docs/`, `docs/CRON.md`, Konfiguration.
7. Tests (Unit ohne DB; Integration erst auf dem Server).
