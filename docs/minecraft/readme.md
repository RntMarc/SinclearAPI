# Minecraft-Statistiken (Ingest)

Ein Minecraft-Server (Plugin) sendet in regelmäßigen Abständen die Vanilla-Spielerstatistiken an die API. Die API
validiert die Daten, speichert den **vollständigen Verlauf** in der Datenbank und hält den jeweils aktuellen Stand
bereit. Auf Basis dieses Verlaufs können später Diagramme und Auswertungen erstellt werden.

> **Status v1:** Umfang ist der **Empfang und die Speicherung** der Daten (`POST /minecraft/stats`) sowie die
> **Verknüpfung von Minecraft-UUIDs mit Beyond-Nutzern** und der Abruf des aktuellen Stands über die gemeinsamen
> Spiele-Endpoints (siehe [games/readme.md](../games/readme.md)). Zusätzlich liefert `GET /minecraft/sources` eine
> aggregierte Quellen-Übersicht über alle Server bzw. einen einzelnen Server. Zeitreihen und Diagramme bleiben
> Nicht-Ziele.

> **Abgrenzung:** Extern verknüpfte Spiele-Accounts mit Pull-Statistiken (z. B. Deadlock) sind in
> [games/readme.md](../games/readme.md) dokumentiert. Dort liegen Statistiken nur im Cache. Minecraft arbeitet
> dagegen im **Push-Modell** (der Server sendet) und speichert **dauerhaft in der Datenbank**.

## Überblick

| Aspekt | Verhalten |
|--------|-----------|
| Richtung | Push: Plugin → API (der Minecraft-Server ist von außen nicht erreichbar) |
| Authentifizierung | Shared Secret (API-Key) im `Authorization: Bearer`-Header, kein JWT |
| Transport | HTTPS erzwungen (`RequireHttpsMiddleware`), JSON |
| Idempotenz | `snapshotId` (UUID); erneutes Senden desselben Snapshots ist gefahrlos |
| Speicherung | Aktueller Stand + vollständiger Verlauf (jede Änderung eines Zählers) |
| Zuordnung | Über die Minecraft-UUID; Verknüpfung mit Beyond-Nutzern über `GameAccount` (`game = 'minecraft'`) |
| Aufbewahrung | Unbegrenzt (kein automatisches Löschen) |

## Authentifizierung

`POST /minecraft/stats` erfordert den Header

```
Authorization: Bearer <MINECRAFT_INGEST_KEY>
```

Warum **kein JWT**: Das Plugin ist ein einzelner, vertrauenswürdiger Server-Client ohne Login-Ablauf. User-JWTs
werden mit demselben RSA-Schlüssel signiert wie alle Access-Tokens; ein Plugin-Token daneben würde Verwechslungsrisiko
ohne Gegenwert schaffen. Das Muster entspricht dem `CentrifugoProxyMiddleware` (Shared Secret per `hash_equals`).

| Aspekt | Verhalten |
|--------|-----------|
| Key-Format | 64 Hex-Zeichen, erzeugt mit `php -r 'echo bin2hex(random_bytes(32));'` |
| Ablage | Nur in der `.env` (`MINECRAFT_INGEST_KEY`), **nicht** in der Datenbank |
| Vergleich | Konstantzeit (`hash_equals`) |
| Key leer/nicht gesetzt | Endpoint antwortet `503 ingest_disabled`; ein leerer Key darf **nie** als gültig matchen |
| Übertragung | Ausschließlich im Header, **nie** als Query-Parameter (würde in Logs landen) |
| Rotation | Neuen Key in `.env` und in der Plugin-Konfiguration eintragen |
| Fehlversuche | Pro IP begrenzt (`MINECRAFT_INVALID_KEY_RATE_LIMIT_PER_MINUTE`, Default 10/min → `429 rate_limited`); ein fehlender Key zählt als Fehlversuch |

Der Key wird geprüft, **bevor** der Body gelesen und geparst wird. Die Prüfung übernimmt der globale
`MinecraftIngestMiddleware`-Guard (pfadgebunden auf `POST /minecraft/stats`, in `public/index.php` vor dem
`BodyParsingMiddleware` registriert). Der Key wird nie geloggt.

## Endpoint

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/minecraft/stats` | Snapshot mit Statistiken eines oder mehrerer Spieler übermitteln |
| `GET` | `/minecraft/sources` | Aggregierte Quellen-Übersicht (JWT), optional per `serverId` gefiltert |

### Request

`Content-Type: application/json`

```json
{
  "schemaVersion": 1,
  "serverId": "MedionSMP",
  "world": "world",
  "snapshotId": "866a12cc-4ea1-4e2b-96d1-c87094707c67",
  "collectedAt": "2026-11-25T22:56:12Z",
  "playerCount": 1,
  "players": [
    {
      "uuid": "f6d584ff-9577-472b-9aa2-88b7878eac37",
      "name": "Notch",
      "dataVersion": 5023,
      "stats": {
        "minecraft:custom": {
          "minecraft:play_time": 14288,
          "minecraft:walk_one_cm": 7845,
          "minecraft:deaths": 2
        }
      }
    }
  ]
}
```

| Feld | Typ | Pflicht | Regel |
|------|-----|---------|-------|
| `schemaVersion` | integer | ja | Muss `1` sein, sonst `400 unsupported_schema_version` |
| `serverId` | string | ja | `^[A-Za-z0-9_.-]{1,64}$` |
| `world` | string | ja | `^[A-Za-z0-9_.-]{1,64}$` (Vanilla-Statistiken sind pro Welt) |
| `snapshotId` | string | ja | UUID; wird auf Kleinbuchstaben normalisiert; Idempotenz-Schlüssel |
| `collectedAt` | string | ja | RFC 3339 mit Offset oder `Z`; max. 5 Minuten in der Zukunft; Vergangenheit erlaubt |
| `playerCount` | integer | nein | Wird ignoriert, maßgeblich ist die Länge von `players` |
| `players` | array | ja | 1 bis `MINECRAFT_MAX_PLAYERS` (Default 200) Einträge, UUIDs eindeutig |
| `players[].uuid` | string | ja | UUID; normalisiert auf Kleinbuchstaben mit Bindestrichen |
| `players[].name` | string | nein | `^[A-Za-z0-9_.\- ]{1,32}$`; aktualisiert den zuletzt bekannten Namen (nur aus dem neuesten Snapshot) |
| `players[].dataVersion` | integer | nein | Minecraft-`DataVersion` des Statistik-Files |
| `players[].stats` | object | ja | `{ "kategorie:name": { "statistik:name": integer } }` |

Regeln für `stats`:

- Kategorie- und Statistik-Schlüssel entsprechen `^[a-z0-9_.-]+:[a-z0-9_./-]+$` (max. 100 Zeichen).
- Werte sind ganze Zahlen `>= 0` (Vanilla-Zähler). Strings, Floats, `null` und Verschachtelungen werden abgelehnt.
- Es gibt **keine feste Whitelist** an Kategorien/Statistiken: Neue Vanilla- oder Mod-Statistiken werden ohne
  API-Änderung gespeichert. Die API prüft nur die Struktur.
- Maximal `MINECRAFT_MAX_STATS_PER_PLAYER` (Default 10000) Zähler pro Spieler.
- `stats` darf leer sein (`{}`); der Spieler gilt dann als im Snapshot anwesend.

Einheiten der Rohwerte (werden unverändert gespeichert, nicht umgerechnet):

| Statistik | Einheit |
|-----------|---------|
| `minecraft:play_time`, `minecraft:total_world_time`, `minecraft:time_since_*`, `minecraft:sneak_time` | Ticks (20 Ticks = 1 s) |
| `minecraft:*_one_cm` | Zentimeter |
| `minecraft:damage_*` | Schaden in Halbherzen ×10 (Vanilla-Rohwert) |
| übrige Zähler | Anzahl |

### Responses

| Status | Body | Bedeutung |
|--------|------|-----------|
| `200` | `{ "snapshotId": "…", "duplicate": false, "accepted": 1, "changedStats": 14 }` | Snapshot verarbeitet |
| `200` | `{ "snapshotId": "…", "duplicate": true, "accepted": 0, "changedStats": 0 }` | Snapshot war bereits bekannt, nichts geändert |
| `400` | `{ "error": "invalid_json" }` | Body ist kein gültiges JSON-Objekt |
| `400` | `{ "error": "unsupported_schema_version" }` | `schemaVersion` nicht unterstützt |
| `400` | `{ "error": "validation_failed", "details": [{ "field": "players.0.uuid", "error": "invalid_uuid" }] }` | Validierungsfehler (max. 20 Details) |
| `401` | `{ "error": "unauthorized" }` / `{ "error": "invalid_key" }` | Key fehlt bzw. ist falsch |
| `403` | `{ "error": "ssl_required" }` | Request nicht über HTTPS |
| `413` | `{ "error": "payload_too_large" }` | Body größer als `MINECRAFT_MAX_BODY_BYTES` |
| `429` | `{ "error": "rate_limited" }` + `Retry-After: 60` | Rate-Limit überschritten |
| `503` | `{ "error": "ingest_disabled" }` | Kein `MINECRAFT_INGEST_KEY` konfiguriert |

Ein Snapshot wird **ganz oder gar nicht** gespeichert (eine Transaktion). Bei `400` wird nichts geschrieben.

Hinweise zur Strukturprüfung:

- `Content-Type` wird nicht hart erzwungen; entscheidend ist, dass der Body ein JSON-Objekt ist.
- Unbekannte Felder werden ignoriert; `playerCount` wird komplett ignoriert (maßgeblich ist die Länge von `players`).

### Verhalten des Plugins (Anforderungen an den Client)

- Alle `players` des Snapshots enthalten die **vollständigen kumulativen** Statistiken (keine Deltas).
- Bei Netzwerkfehlern, `429` und `5xx` mit **derselben `snapshotId`** wiederholen (bei `429` `Retry-After` beachten).
  Bei `4xx` außer `429` nicht wiederholen, der Fehler liegt im Payload bzw. Key.
- Nicht in der Aufzählung enthaltene Spieler werden nicht verändert, ein Snapshot ist also eine **Teilmenge**.
- Empfohlene Frequenz: alle 5 Minuten, nur wenn Spieler online sind oder sich Daten geändert haben.

## Lese-Endpoint: Quellen-Übersicht

`GET /minecraft/sources` (JWT erforderlich) liefert die getrackten Quellen mit ihrem letzten Stand, den über alle
Spieler summierten Vanilla-Statistiken, der Spieleranzahl und einer Rangliste der beteiligten Spieler. Der Endpoint
ist rein lesend; er ändert keine Daten und nutzt nicht den Ingest-Key.

| Aspekt | Verhalten |
|--------|-----------|
| Authentifizierung | JWT (`Authorization: Bearer <Access-Token>`), alle eingeloggten Nutzer (`MinecraftStatsPolicy`) |
| Scope | ohne `serverId` → alle Server (`global`); mit `serverId` → nur dieser Server (`server`) |
| Datenquelle | `MinecraftSource` + `MinecraftStatCurrent` (die Summen sind der aktuelle Stand je Zähler) |
| Spieler | alle Spieler mit mindestens einem erfassten Zähler – **unabhängig** von einer Beyond-Verknüpfung |
| Rangliste | absteigend nach dem gewählten Zähler (`sortCategory`/`sortStat`), Tie-Break Name, dann UUID |

### Query-Parameter

| Parameter | Default | Regel |
|-----------|---------|-------|
| `serverId` | – | `^[A-Za-z0-9_.-]{1,64}$`; leer/fehlend = global |
| `sortCategory` | `minecraft:custom` | Kategorie des Zählers, nach dem geranked wird |
| `sortStat` | `minecraft:play_time` | Statistik des Zählers, nach dem geranked wird |
| `limit` | alle | 1–500; trimmt nur die Rangliste, `playerCount` und `totals` bleiben vollständig |
| `includeStats` | `false` | `true` ergänzt je Ranglisteneintrag alle Einzelzähler des Spielers |

Ungültige Parameter liefern `400` mit `invalid_server_id`, `invalid_sort_category`, `invalid_sort_stat`,
`invalid_limit` oder `invalid_include_stats`.

### Antwort

```json
{
  "data": {
    "scope": "global",
    "serverId": null,
    "rankedBy": { "category": "minecraft:custom", "stat": "minecraft:play_time" },
    "sources": [
      {
        "sourceId": 1,
        "serverId": "MedionSMP",
        "world": "world",
        "lastCollectedAt": "2026-11-28T11:55:00Z",
        "playerCount": 2,
        "totals": { "minecraft:custom": { "minecraft:play_time": 150, "minecraft:deaths": 3 } },
        "ranking": [
          {
            "rank": 1,
            "uuid": "f6d584ff-9577-472b-9aa2-88b7878eac37",
            "name": "Notch",
            "linkedUserId": "usr_123",
            "avatarUrl": "https://mc-heads.net/avatar/f6d584ff-9577-472b-9aa2-88b7878eac37/64",
            "value": 100
          },
          {
            "rank": 2,
            "uuid": "ca48cfb5-bfcb-4f25-bbbf-966cb5892c80",
            "name": "Alex",
            "linkedUserId": null,
            "avatarUrl": null,
            "value": 50
          }
        ]
      }
    ],
    "servers": [
      {
        "serverId": "MedionSMP",
        "worlds": ["world", "nether"],
        "lastCollectedAt": "2026-11-28T11:55:00Z",
        "playerCount": 3,
        "totals": { "minecraft:custom": { "minecraft:play_time": 180 } },
        "ranking": [
          {
            "rank": 1,
            "uuid": "f6d584ff-9577-472b-9aa2-88b7878eac37",
            "name": "Notch",
            "linkedUserId": "usr_123",
            "avatarUrl": "https://mc-heads.net/avatar/f6d584ff-9577-472b-9aa2-88b7878eac37/64",
            "value": 130
          }
        ]
      }
    ],
    "generatedAt": "2026-11-28T12:00:00Z"
  },
  "meta": { "sourceCount": 1, "serverCount": 1 }
}
```

Semantik:

- **`sources`** – je `(serverId, world)`: `lastCollectedAt` ist das jüngste `collectedAt` der Snapshots dieser Quelle,
  `totals` die Summe **aller** aktuellen Zähler über alle Spieler der Quelle.
- **`servers`** – je `serverId` über alle Welten: `totals` sind die Summen der Quellen, `playerCount` zählt jeden
  Spieler nur einmal (`COUNT(DISTINCT)`), `worlds` listet die vorhandenen Welten.
- **`playerCount`** – Spieler mit mindestens einem Zähler in `MinecraftStatCurrent`; Spieler ohne Zähler (z. B. nur
  im Snapshot anwesend) zählen nicht. `limit` verändert den Wert nicht.
- **`ranking[].value`** – Einzelwert des gewählten Zählers (Server-Rangliste: Summe über die Welten). Fehlt der
  Zähler bei einem Spieler, taucht er nicht in dieser Rangliste auf; `totals` bleibt davon unberührt.
- **`linkedUserId`/`avatarUrl`** – aus `GameAccount` (`game = 'minecraft'`, `accountId = UUID`); `null`, wenn keine
  Verknüpfung besteht. Die Statistik ist unabhängig von der Verknüpfung.
- **`includeStats`** – ergänzt `stats` (alle Einzelzähler; Server-Rangliste: über Welten summiert). Ohne Flag fehlt
  das Feld, damit die Antwort bei vielen Zählern klein bleibt.
- `generatedAt` und alle Zeitstempel sind UTC (RFC 3339 mit `Z`).

## Verknüpfung mit Beyond-Nutzern

Nutzer verknüpfen ihren Minecraft-Account über die gemeinsamen Spiele-Endpoints
(`PUT /user/me/games/minecraft` mit `{ "accountId": "<UUID|Username>" }`). Die
UUID bzw. der Username wird über die Mojang-API aufgelöst und als kanonische
UUID in `GameAccount` gespeichert. Details, Fehlercodes und das Statistik-Format
sind in [games/readme.md](../games/readme.md) → Abschnitt „Minecraft“ dokumentiert.

Wesentliche Punkte:

- `accountId` = kanonische UUID (lowercase, mit Bindestrichen).
- `displayName` = Mojang-Name, `avatarUrl` = `{MINECRAFT_AVATAR_BASE_URL}/{uuid}/64`.
- Exklusiv: `UNIQUE (game, accountId)`; Konflikt → `409 account_already_linked`.
- Der Name aus Mojang wird beim Verknüpfen gespeichert; der zuletzt vom Plugin
  gemeldete Name (`MinecraftPlayer.name`) bleibt davon unabhängig und wird in der
  Statistik-Antwort (`stats.player.name`) verwendet.
- `GameAccount.lastSyncedAt` wird nach jedem erfolgreichen Snapshot gesetzt
  (`GREATEST`, Nachzügler setzen ihn nicht zurück).

## Idempotenz und Reihenfolge

- `snapshotId` ist Primärschlüssel von `MinecraftSnapshot`. Ein bereits bekannter Snapshot liefert
  `200 duplicate: true`, auch wenn zwei identische Requests gleichzeitig eintreffen (Primärschlüssel-Konflikt wird als
  Duplikat behandelt).
- **Nachzügler** (`collectedAt` älter als der aktuelle Stand des Spielers) werden akzeptiert und im Verlauf
  eingeordnet, **verändern aber den aktuellen Stand nicht**. Ihre Änderungen werden gegen den letzten bekannten Wert
  vor `collectedAt` verglichen.
- Zeitstempel werden als UTC (`DATETIME(3)`) gespeichert und als RFC 3339 mit `Z` ausgegeben (siehe
  Date/Time-Convention in `AGENTS.md`).

## Datenmodell

Alle Tabellen werden per Migration in `database/migrations/` angelegt. UUID-Spalten verwenden
`CHARACTER SET ascii` (36 Bytes statt 144 bei utf8mb4).

### Speicherstrategie: Änderungen statt vollständiger Snapshots

Ein vollständiger Vanilla-Statistiksatz eines aktiven Spielers umfasst Hunderte bis Tausende Zähler. Würde jeder
Snapshot vollständig abgelegt, wüchse die Datenbank bei 5-Minuten-Intervallen unnötig stark, obwohl sich zwischen zwei
Snapshots nur wenige Zähler ändern. Deshalb gilt:

- `MinecraftStatCurrent` hält den **aktuellen Wert jedes Zählers**.
- `MinecraftStatHistory` erhält **nur dann eine Zeile, wenn sich der Wert eines Zählers gegenüber dem vorherigen Wert
  geändert hat** (die erste Messung eines Zählers ist immer eine Änderung).
- Es geht **keine Information verloren**: Der Wert eines Zählers zum Zeitpunkt *T* ist der letzte
  History-Eintrag mit `collectedAt <= T`. Ein unveränderter Zähler ist die Fortsetzung des letzten Werts.
- Zähler können auch sinken (z. B. nach Statistik-Reset); jede Abweichung gilt als Änderung.
- Ein in einem neueren Snapshot **fehlender** Zähler bleibt unverändert (Current bleibt bestehen). Ein vollständiger
  Statistik-Reset muss das Plugin deshalb explizit als Wert `0` senden.
- Die Anwesenheit eines Spielers in einem Snapshot (auch ohne geänderte Zähler) wird separat in
  `MinecraftSnapshotPlayer` festgehalten, z. B. für Online-Zeit-Diagramme.

Der rohe Request-Body wird **nicht** gespeichert.

### Tabellen

**`MinecraftSnapshot`** – Eingangsprotokoll, erzwingt Idempotenz

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | char(36) ascii | Primärschlüssel (= `snapshotId`) |
| `sourceId` | int | FK → `MinecraftSource` |
| `schemaVersion` | smallint | Schema-Version des Payloads |
| `collectedAt` | datetime(3) | Zeitpunkt der Erhebung (UTC) |
| `receivedAt` | datetime(3) | Zeitpunkt des Empfangs (UTC) |
| `playerCount` | smallint | Anzahl der gespeicherten Spieler |

Index: `(sourceId, collectedAt)`.

**`MinecraftSource`** – Server + Welt als Wörterbuch

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | int auto_increment | Primärschlüssel |
| `serverId` | varchar(64) | Server-Kennung |
| `world` | varchar(64) | Weltname |

`UNIQUE (serverId, world)`.

**`MinecraftPlayer`** – bekannte Spieler

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `uuid` | char(36) ascii | Primärschlüssel (Minecraft-UUID) |
| `name` | varchar(32) NULL | Zuletzt gemeldeter Name (nur aus dem neuesten Snapshot; ältere Nachzügler überschreiben ihn nicht) |
| `firstSeenAt` | datetime(3) | Frühester Snapshot mit diesem Spieler (`MIN`) |
| `lastSeenAt` | datetime(3) | Spätester Snapshot mit diesem Spieler (`MAX`) |

**`MinecraftSnapshotPlayer`** – Anwesenheit je Snapshot

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `snapshotId` | char(36) ascii | FK → `MinecraftSnapshot` (`ON DELETE CASCADE`) |
| `playerUuid` | char(36) ascii | FK → `MinecraftPlayer` |
| `dataVersion` | int NULL | Minecraft-`DataVersion` |

`PRIMARY KEY (snapshotId, playerUuid)`, Index auf `playerUuid`.

**`MinecraftStatKey`** – Statistik-Wörterbuch (verhindert die Wiederholung langer Namen je Zeile)

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `id` | int auto_increment | Primärschlüssel |
| `category` | varchar(100) | z. B. `minecraft:custom` |
| `stat` | varchar(100) | z. B. `minecraft:play_time` |

`UNIQUE (category, stat)`.

**`MinecraftStatCurrent`** – aktueller Stand

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `sourceId` | int | FK → `MinecraftSource` |
| `playerUuid` | char(36) ascii | FK → `MinecraftPlayer` |
| `statKeyId` | int | FK → `MinecraftStatKey` |
| `value` | bigint | Aktueller Zählerstand |
| `collectedAt` | datetime(3) | Zeitpunkt des aktuellen Werts (UTC) |
| `snapshotId` | char(36) ascii | Snapshot, aus dem der Wert stammt |

`PRIMARY KEY (sourceId, playerUuid, statKeyId)`.

**`MinecraftStatHistory`** – Verlauf (nur Änderungen)

| Spalte | Typ | Bedeutung |
|--------|-----|-----------|
| `sourceId` | int | FK → `MinecraftSource` |
| `playerUuid` | char(36) ascii | FK → `MinecraftPlayer` |
| `statKeyId` | int | FK → `MinecraftStatKey` |
| `collectedAt` | datetime(3) | Zeitpunkt der Änderung (UTC) |
| `value` | bigint | Neuer Zählerstand |
| `snapshotId` | char(36) ascii | Snapshot, aus dem die Änderung stammt (kein FK; Index `idx_minecraft_stat_history_snapshot`) |

`PRIMARY KEY (sourceId, playerUuid, statKeyId, collectedAt)`. Der Primärschlüssel ist so gewählt, dass der Verlauf
eines Zählers (z. B. Spielzeit eines Spielers) als zusammenhängender Index-Bereich in Zeitreihenfolge gelesen wird.
Bei einer Millisekunden-Kollision desselben Zählers zur selben `collectedAt` gewinnt der zuletzt geschriebene Wert
(`ON DUPLICATE KEY UPDATE`).

### Größenabschätzung

Eine History-Zeile belegt grob 60–100 Bytes inkl. Index. Bei 10 Spielern mit durchschnittlich 50 geänderten Zählern je
5-Minuten-Snapshot im Dauerbetrieb (Worst Case) entstehen etwa 140.000 Zeilen pro Tag bzw. rund 400 MB pro Monat. Der
reale Wert liegt deutlich darunter, da Spieler nur zeitweise online sind und nur geänderte Zähler gespeichert werden.
Ein Aufräum-Job ist daher in v1 nicht vorgesehen. Falls später gewünscht, wäre dies ein Cron-Task
(siehe [CRON.md](../CRON.md)).

### Verarbeitung eines Snapshots (Transaktion)

1. Auth, Größe, Rate-Limit und Validierung (nichts wird vor erfolgreicher Validierung geschrieben).
2. Snapshot-Duplikat prüfen (`SELECT`); wenn bereits bekannt → `200 duplicate: true`, Abbruch.
3. `MinecraftSource` bei Bedarf anlegen (get-or-create), dann `MinecraftSnapshot` einfügen (die Quelle muss wegen des
   FK `sourceId` vorher existieren). Ein Primärschlüssel-Konflikt bei parallelen identischen Requests wird als
   Duplikat behandelt.
4. `MinecraftStatKey` bei Bedarf anlegen (get-or-create).
5. Je Spieler: `MinecraftPlayer` anlegen/aktualisieren (`firstSeenAt` = `MIN`, `lastSeenAt` = `MAX`, Name nur aus dem
   neuesten Snapshot), `MinecraftSnapshotPlayer` einfügen.
6. Je Spieler: Für jeden eingehenden Zähler wird der letzte bekannte Wert zum Zeitpunkt `collectedAt` ermittelt
   (bei `collectedAt` älter als der gespeicherte Stand des Zählers aus dem Verlauf, sonst aus `MinecraftStatCurrent`).
   Für jeden geänderten oder neuen Zähler wird eine Zeile in `MinecraftStatHistory` geschrieben;
   `MinecraftStatCurrent` wird pro Zähler nur aktualisiert, wenn `collectedAt` nicht älter als der gespeicherte Stand
   dieses Zählers ist.
7. Commit; Antwort `200`.

## Sicherheit

| Maßnahme | Umsetzung |
|----------|-----------|
| Transport | `RequireHttpsMiddleware` (403 `ssl_required`) |
| Authentifizierung | `MinecraftIngestMiddleware` (globaler Guard), `hash_equals`, vor dem Parsen des Bodys |
| Autorisierung | Minimale `MinecraftIngestPolicy` als Defense-in-Depth im Controller (Key gültig = Server-Principal; keine Nutzerrechte) |
| Lese-Endpoint | JWT über `AuthenticationMiddleware`, `MinecraftStatsPolicy` als Defense-in-Depth |
| Missbrauchsschutz | Rate-Limit pro Key (`MINECRAFT_RATE_LIMIT_PER_MINUTE`, Default 30) und pro IP bei ungültigem Key |
| Größenlimit | `Content-Length`-Vorabprüfung und Prüfung der tatsächlich gelesenen Bytes (`MINECRAFT_MAX_BODY_BYTES`, Default 2 MB) |
| SQL | Ausschließlich Prepared Statements; Schlüssel/Werte nur nach Regex-/Typprüfung |
| Logging | Kein Key, keine Payloads im Log; nur `snapshotId`, `serverId`, Spieleranzahl, Fehlercode |
| Dateizugriff | Keine neuen Dateien im Webroot; `.env` ist per `.htaccess` gesperrt |

## Konfiguration

`config/settings.php`:

```php
$settings['minecraft'] = [
    'ingest_key'                       => $_ENV['MINECRAFT_INGEST_KEY'] ?? '',
    'max_body_bytes'                   => (int)($_ENV['MINECRAFT_MAX_BODY_BYTES'] ?? 2097152),
    'max_players'                      => (int)($_ENV['MINECRAFT_MAX_PLAYERS'] ?? 200),
    'max_stats_per_player'             => (int)($_ENV['MINECRAFT_MAX_STATS_PER_PLAYER'] ?? 10000),
    'rate_limit_per_minute'            => (int)($_ENV['MINECRAFT_RATE_LIMIT_PER_MINUTE'] ?? 30),
    'invalid_key_rate_limit_per_minute' => (int)($_ENV['MINECRAFT_INVALID_KEY_RATE_LIMIT_PER_MINUTE'] ?? 10),
    'profile_api_base_url'             => $_ENV['MINECRAFT_PROFILE_API_BASE_URL'] ?? 'https://api.mojang.com',
    'session_api_base_url'             => $_ENV['MINECRAFT_SESSION_API_BASE_URL'] ?? 'https://sessionserver.mojang.com',
    'avatar_base_url'                  => $_ENV['MINECRAFT_AVATAR_BASE_URL'] ?? 'https://mc-heads.net/avatar',
];
```

Die Settings müssen zusätzlich als `public array $minecraft = []` in `src/Application/Settings.php` ergänzt werden,
da die Klasse per `new Settings(...$settingsArray)` konstruiert wird.

`.env.example`:

```dotenv
# Minecraft-Statistiken (Plugin → API). Key erzeugen: php -r 'echo bin2hex(random_bytes(32));'
# Leer = Endpoint deaktiviert (503 ingest_disabled).
MINECRAFT_INGEST_KEY=
MINECRAFT_MAX_BODY_BYTES=2097152
MINECRAFT_MAX_PLAYERS=200
MINECRAFT_MAX_STATS_PER_PLAYER=10000
MINECRAFT_RATE_LIMIT_PER_MINUTE=30
MINECRAFT_INVALID_KEY_RATE_LIMIT_PER_MINUTE=10
# Minecraft-Account-Verknüpfung (UUID/Username-Auflösung über Mojang).
MINECRAFT_PROFILE_API_BASE_URL=https://api.mojang.com
MINECRAFT_SESSION_API_BASE_URL=https://sessionserver.mojang.com
MINECRAFT_AVATAR_BASE_URL=https://mc-heads.net/avatar
```

Hinweis: Webserver-/PHP-Limits (`post_max_size`, `upload_max_filesize`) müssen mindestens
`MINECRAFT_MAX_BODY_BYTES` erreichen, damit überlange Bodies zuverlässig mit `413` beantwortet werden können.

## Architektur

```
public/index.php                            // globaler, pfadgebundener Ingest-Guard (vor BodyParsingMiddleware)
config/routes.php                           // POST /minecraft/stats (kein Route-Middleware nötig),
                                            // GET /minecraft/sources (JWT)
src/Middleware/MinecraftIngestMiddleware.php  // Shared Secret, Rate-Limit, Body-Limit
src/Controllers/MinecraftIngestController.php
src/Controllers/MinecraftStatsController.php            // GET /minecraft/sources (JWT)
src/Services/Minecraft/MinecraftSnapshotValidator.php   // Struktur- und Wertprüfung, normalisiert UUIDs/Zeiten
src/Services/Minecraft/MinecraftValidationException.php  // Fehlercode + Details (max. 20)
src/Services/Minecraft/MinecraftIngestService.php       // Transaktion, Diff gegen Current, Idempotenz
src/Services/Minecraft/MinecraftStatsQuery.php          // Query-Validierung (ohne DB testbar)
src/Services/Minecraft/MinecraftOverviewService.php     // Aggregation, Ranking, Formatierung
src/Repository/MinecraftSnapshotRepository.php
src/Repository/MinecraftPlayerRepository.php
src/Repository/MinecraftStatRepository.php              // Source/StatKey/Current/History/SnapshotPlayer
src/Repository/MinecraftOverviewRepository.php          // Lese-Queries (Summen, Zählung, Ranking)
src/Security/Policy/MinecraftIngestPolicy.php           // minimale Defense-in-Depth-Policy
src/Security/Policy/MinecraftStatsPolicy.php            // Defense-in-Depth für den Lese-Endpoint
src/Services/Games/Minecraft/                           // Verknüpfung + Statistik-Abruf (siehe games/readme.md)
database/migrations/20261127120000_game_account_unique_account.sql
```

DI-Wiring per `autowire()` in `config/dependencies.php`. Der Guard wird (wie `CentrifugoProxyMiddleware`) mit den
Settings aus `config/settings.php` konstruiert; der Validator erhält `max_players` und `max_stats_per_player`.

## Admin-Dashboard, Notifications, Cron

- **Admin-Dashboard:** Es gibt keine admin-bearbeitbaren Daten (Key liegt in `.env`). Die Quellen-Übersicht ist ein
  Lese-Endpoint für Clients; eine Admin-Ansicht der Daten wäre eine spätere Erweiterung.
- **Notifications:** Keine neuen Notification-Typen.
- **Cron:** Keine eigenen Tasks in v1. Der bestehende `refresh_game_stats` überspringt Minecraft
  (Push-Modell, `isPushBased() === true`); `GameAccount.lastSyncedAt` wird stattdessen beim Ingest gesetzt.

## Nicht-Ziele (v1)

- Zeitreihen-/History-Endpunkte (Verlauf je Zähler) und Diagramme; ausgeliefert wird nur der aktuelle Stand –
  als Spieler-Sicht (`/games/minecraft/stats`) und aggregiert (`GET /minecraft/sources`).
- Mehrere Server mit eigenen Keys (Keys in der DB inkl. Admin-Verwaltung), HMAC-Signatur der Requests.
- Aufbewahrungsfristen / Löschjobs.
