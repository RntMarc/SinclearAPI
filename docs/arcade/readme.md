# Arcade – selbst gehostete Spiele

Arcade ergänzt Beyond um **selbst programmierte, im Browser laufende Spiele**.
Nutzer starten ein Spiel, das als HTML/JS direkt vom Webserver ausgeliefert
wird, und messen sich über **Highscores** mit den anderen Beyond-Nutzern.

Arcade ist ein eigener Namensraum (`/arcade`) und vollständig unabhängig vom
bestehenden Feature `games` (verknüpfte Spiele-Accounts/Statistiken). Es gibt
keine Vermischung mit `GameAccount`, `GameStats` oder der `GameRegistry`.

## Überblick

| Aspekt | Verhalten |
|--------|-----------|
| Auslieferung | Spiele liegen als HTML/JS unter `public/arcade/<slug>/` und werden statisch ausgeliefert |
| Katalog | Spiel-Metadaten in der Tabelle `ArcadeGame`, verwaltet im Admin-Dashboard |
| Zugang | Kurzlebiges Session-Token pro Spiel, automatisch erzeugt und entwertet |
| Verlängerung | In-Session-Rotation über `POST /arcade/session/renew` |
| Score | Volle Versuchs-Historie in `ArcadeScore` |
| Leaderboard | Bestwert je Nutzer, sortiert nach `ArcadeGame.scoreDirection` |
| Sichtbarkeit | JWT für alle Katalog-/Leaderboard-/Historien-Endpunkte |
| Pagination | Projekt-Standard `page` + `limit` mit `meta { page, limit, total, totalPages }` |

## Spiele

| Slug | Titel | Technik | Details |
|------|-------|---------|---------|
| `rocket-boost` | Rocket Boost | Go/WASM + JS-Schale | [Rocket Boost](games/rocket-boost.md) |

`rocket-boost` ist das Referenzspiel: klein, ohne externe Assets, mit
Session-Token, automatischer Score-Abgabe und Debug-Modus. Es zeigt, wie ein
Spiel mit Build-Schritt (Go → WASM) in die Arcade-Auslieferung passt. Der
Katalog-Eintrag wird von
`database/migrations/20261101180000_seed_rocket_boost_game.sql` angelegt.

## Datenmodell

Migration: `database/migrations/20261101160000_create_arcade.sql` (rerun-sicher
durch `CREATE TABLE IF NOT EXISTS`; wird vom Betreiber auf dem Server
angewendet).

### `ArcadeGame`

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | varchar(191) | UUID (UUIDv7) |
| `slug` | varchar(50) | Verzeichnisname unter `public/arcade/<slug>/`, eindeutig |
| `title` | varchar(191) | Anzeigename |
| `description` | text | optionale Katalogbeschreibung |
| `thumbnailUrl` | varchar(1024) | optionales Vorschaubild |
| `scoreDirection` | enum(`asc`,`desc`) | `desc` = größer ist besser, `asc` = kleiner ist besser |
| `isActive` | tinyint(1) | nur aktive Spiele erscheinen im Katalog |
| `sortOrder` | int | aufsteigende Sortierung im Katalog |
| `createdAt`/`updatedAt` | datetime(3) | UTC |

### `ArcadeSession`

Kurzlebiges Token, das an Nutzer und Spiel gebunden ist. Gespeichert wird nur
`tokenHash = SHA-256(token)`; das Klartext-Token existiert ausschließlich in der
Antwort auf die Ausstellung/Rotation.

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | varchar(191) | UUID |
| `userId` | varchar(191) | Beyond-Nutzer-ID |
| `gameId` | varchar(191) | `ArcadeGame.id` |
| `tokenHash` | varchar(64) | SHA-256 des Klartext-Tokens |
| `expiresAt` | datetime(3) | Ablaufzeitpunkt (UTC) |
| `revokedAt` | datetime(3) | gesetzt bei Rotation, explizitem Widerruf oder Löschen |
| `createdAt` | datetime(3) | UTC |

### `ArcadeScore`

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | varchar(191) | UUID |
| `userId` | varchar(191) | Beyond-Nutzer-ID |
| `gameId` | varchar(191) | `ArcadeGame.id` |
| `score` | bigint | ganzzahliger Score |
| `meta` | JSON | spielspezifische Zusatzdaten (z. B. Level, Zeit) |
| `createdAt` | datetime(3) | UTC |

Es gibt keine Fremdschlüssel zur `User`-Tabelle (konsistent zu `GameAccount`).
Leaderboard und Historie liefern nur Nutzer, die noch in der DB existieren.
Beim Löschen eines Spiels im Admin werden zugehörige Sessions und Scores
mitgelöscht.

## API-Endpoints

Alle folgenden Pfade sind relativ zum API-Basispfad `/api/v2` (die statische
Play-URL liegt dagegen unter `<APP_URL>/api/arcade/<slug>/index.html`).

Alle `/arcade/games/...`-Endpoints erfordern ein gültiges JWT im
`Authorization: Bearer <token>`-Header. Die spiel-internen Endpoints
(`POST /arcade/scores`, `POST /arcade/session/renew`) authentifizieren sich
stattdessen über das Arcade-Session-Token.

### Katalog & Sichtbarkeit (JWT)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/arcade/games` | Aktive Spiele inkl. `scoreDirection` und Basis-`playUrl` |
| `GET` | `/arcade/games/{slug}` | Detail eines aktiven Spiels |
| `GET` | `/arcade/games/{slug}/leaderboard` | Bestwert je Nutzer inkl. Rang und eigenem Eintrag (`me`) |
| `GET` | `/arcade/games/{slug}/scores/{userId}` | Volle Score-Historie eines Nutzers |

Unbekannte oder inaktive Slugs liefern `404 unknown_game`. Für Historien gilt
zusätzlich `404 user_not_found` bzw. `404 score_not_found`, wenn der Nutzer
nicht existiert oder noch keinen Score besitzt.

### Session (JWT)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/arcade/games/{slug}/session` | Session-Token ausstellen (`201`) |
| `DELETE` | `/arcade/games/{slug}/session` | Alle aktiven Sessions des Nutzers für das Spiel widerrufen (`204`) |

Antwort auf die Ausstellung:

```json
{
  "token": "…",
  "expiresAt": "2026-11-01T16:15:00Z",
  "playUrl": "https://api.example.com/api/arcade/rocket-boost/index.html#arcade_token=…"
}
```

### Spiel-intern (Session-Token)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/arcade/session/renew` | Token rotieren und Laufzeit verlängern |
| `POST` | `/arcade/scores` | Score-Versuch speichern |

Das Token wird bevorzugt im `Authorization: Bearer <token>`-Header gesendet;
alternativ akzeptiert die Middleware das Body-Feld `token`. Fehlendes Token →
`401 unauthorized`; abgelaufenes/widerrufenes Token → `401 invalid_session`.

Score-Body:

```json
{
  "score": 42,
  "meta": { "durationMs": 61230 }
}
```

Antwort (`201`):

```json
{
  "score": { "id": "…", "userId": "…", "gameId": "…", "score": 42, "meta": { "durationMs": 61230 }, "createdAt": "…" },
  "bestScore": 42,
  "isPersonalBest": true
}
```

`score` muss eine ganze JSON-Zahl sein. `meta` muss ein JSON-Objekt (oder
`null`/leer) sein; Arrays werden mit `400 invalid_meta` abgelehnt. Die Felder
in `meta` sind spielspezifisch; `rocket-boost` sendet `durationMs`.

## Session-Token-Lebenszyklus

1. **Erzeugung:** `bin2hex(random_bytes(64))`; gespeichert wird nur
   `hash('sha256', $token)`. TTL aus `ARCADE_SESSION_TTL` (Default 900 Sekunden).
2. **Validierung:** `ArcadeSessionMiddleware` bildet den Hash, sucht die Session
   und prüft `revokedAt IS NULL` sowie `expiresAt > NOW(3)`. Bei Erfolg liegen
   `userId`, `gameId` und die Session als Request-Attribut vor.
3. **Rotation:** `POST /arcade/session/renew` entwertet das alte Token
   (`revokedAt`) und stellt ein neues mit frischer Laufzeit aus. Eine Rotation
   ist nur mit einem noch gültigen Token möglich.
4. **Widerruf:** automatisch durch Ablauf, explizit per `DELETE`-Endpoint oder
   Rotation des alten Tokens, sowie durch den Cron-Task
   `cleanup_arcade_sessions`.
5. **Cron-Cleanup:** stündlich werden abgelaufene und widerrufene Sessions
   gelöscht (`cleanup_arcade_sessions`).

## Token-Übergabe im URL-Fragment

Das Token steht in der Play-URL im **URL-Fragment**
(`#arcade_token=…`). Fragmente werden nicht an den Server übertragen und landen
daher nicht in Server-Logs, Referrern oder Proxy-Logs. Das Spiel-JS liest das
Token über `location.hash`.

## Vertrag für Spiel-Autoren

1. Spieldateien unter `public/arcade/<slug>/` ablegen (`index.html`, JS/CSS/
   Assets). Der Slug muss exakt dem `ArcadeGame.slug` entsprechen.
2. Token aus `location.hash` lesen:

   ```js
   const params = new URLSearchParams(location.hash.replace(/^#/, ''));
   const token = params.get('arcade_token');
   ```

3. Score abgeben:

   ```js
   await fetch('/api/v2/arcade/scores', {
     method: 'POST',
     headers: {
       'Authorization': 'Bearer ' + token,
       'Content-Type': 'application/json'
     },
     body: JSON.stringify({ score: 1234, meta: { level: 7 } })
   });
   ```

4. Optional vor Ablauf verlängern:

   ```js
   await fetch('/api/v2/arcade/session/renew', {
     method: 'POST',
     headers: { 'Authorization': 'Bearer ' + token }
   });
   ```

   Die Antwort enthält ein neues `token` und eine neue `playUrl`. Das alte
   Token ist danach ungültig.

### Asset-Regeln

- **Keine `.json`-Dateien:** Die Root-`.htaccess` blockiert `.json` global
  (`<FilesMatch "\.json$">`), auch unter `public/arcade/`. Spieldaten gehören
  inline ins JS/HTML oder werden über die API geladen.
- Ebenfalls blockiert: `.md`, `.yaml`, `.yml`, `.sql`, `.sh`, `.log`, `.lock`,
  `.dist`, `.ini`, `.bak`, `.go`, `.mod`, `.sum` sowie alle Dotfiles.
- `.wasm` ist erlaubt, braucht aber `Content-Type: application/wasm`.
  `public/.htaccess` setzt das per `AddType`; Clients sollten zusätzlich einen
  `arrayBuffer`-Fallback für `WebAssembly.instantiateStreaming` haben.
- Statische Dateien durchlaufen keine PHP-Middleware und erhalten damit auch
  keine Security-Header der API. `public/.htaccess` setzt lediglich
  `X-Content-Type-Options: nosniff`. Spiele müssen selbst auf sichere Inhalte
  achten (keine externen Skripte, kein `eval`).

### Build & Deployment

Spiele mit Build-Schritt (z. B. Go/WASM) legen ihre Quellen unter
`arcade-src/<slug>/` ab. Das Verzeichnis ist per `.htaccess` gesperrt und wird
nie ausgeliefert. Build-Artefakte gehören **nicht** ins Repository, weil auf
dem Server keine Go-Toolchain installiert ist: `update.sh` baut lokal und
überträgt die Artefakte nach erfolgreichem Deploy per `scp`.

```bash
./arcade-src/rocket-boost/build.sh   # baut rocket.wasm + kopiert wasm_exec.js
```

Die Artefakte sind in `.gitignore` eingetragen. `wasm_exec.js` und das
WASM-Binary müssen aus derselben Go-Version stammen.

## Auslieferung & Webserver

- Die `.htaccess` der API (deployt unter `/api/`) leitet `^arcade/<slug>/…`
  auf `public/arcade/<slug>/…` um; öffentlich erreichbar sind die Spiele damit
  unter `/api/arcade/<slug>/…`. Nur harmlose Pfadzeichen sind erlaubt; Segmente
  dürfen nicht mit `.` beginnen (keine Dotfiles/Secrets).
- `public/.htaccess` blockiert zusätzlich Dotfiles und typische Secret-/Quell-
  text-Dateien (`.env`, `.log`, `.sql`, `.md`, `.sh`, …) auch beim direkten
  Abruf unter `/public/…`.
- `public/.htaccess` setzt `AddType application/wasm .wasm`, komprimiert
  `.wasm`/`.js`/`.css`/`.html` per `mod_deflate` und liefert diese Dateien mit
  `Cache-Control: no-cache` aus. Die Revalidierung per ETag/304 verhindert
  veraltete Builds im WebView-Cache, ohne Cache-Busting-Query.
- Quellen von Build-Spielen liegen unter `arcade-src/`; das Verzeichnis wird
  von der Root-`.htaccess` gesperrt. `.go` ist zusätzlich als sensible Endung
  blockiert.
- `Options -Indexes` bleibt aktiv. Die bestehenden Blockierungen für `src/`,
  `config/`, `vendor/`, `docs/`, `.env`, `.sql`, `.md` usw. bleiben unverändert.
- `APP_URL` muss auf den Host zeigen, unter dem `/api/arcade/...` erreichbar ist
  (üblicherweise derselbe API-Host).

## Admin-Dashboard

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/admin/arcade` | HTML-Seite mit Übersicht und Formularen |
| `GET` | `/admin/arcade/json` | Alle Spiele (auch inaktive) als JSON |
| `POST` | `/admin/arcade` | Spiel anlegen |
| `PUT` | `/admin/arcade/{id}` | Spiel bearbeiten |
| `DELETE` | `/admin/arcade/{id}` | Spiel inkl. Sessions/Scores löschen |

Das Formular pflegt Slug, Titel, Beschreibung, `thumbnailUrl`,
`scoreDirection`, `isActive` und `sortOrder`. Die Spiel-Dateien werden nicht
über das Dashboard verwaltet.

## Konfiguration

```dotenv
ARCADE_SESSION_TTL=900
```

`config/settings.php` stellt den Wert als `$settings['arcade']['session_ttl']`
bereit.

## Sicherheit / Grenzen

- Das Token wird serverseitig erzeugt und ist an Nutzer + Spiel gebunden; der
  Client kann es nicht fälschen.
- Es gibt **keinen** „harten“ Cheat-Schutz: Der Client besitzt sein Token und
  könnte Scores direkt an den Endpoint senden. Das ist für die kleine,
  persönlich bekannte Nutzerbasis bewusst akzeptiert.
- Nicht Teil von v1: Echtzeit-Multiplayer, Highscore-Notifications.
