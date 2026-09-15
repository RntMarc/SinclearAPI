# Chat / Direktnachrichten – Matrix-Auslagerung

> **Status:** Phase 1 umgesetzt — Matrix-Account-Provisionierung und
> Anzeigename-Sync über einen Application Service (Continuwuity).
> Noch **nicht** enthalten: Räume, Nachrichten, E2EE, Avatar-Sync, Login-Flow.

Die Sinclear Beyond API lagert das Messaging perspektivisch auf einen
Matrix-Homeserver (Continuwuity, eigener Docker-Container) aus. Diese Phase legt
das Fundament: Das PHP-Backend provisioniert automatisch — und ausschließlich
über einen privilegierten Application Service (AS) — Matrix-Accounts für
Sinclear-Nutzer und hält deren Anzeigenamen synchron.

Der bisherige interne Chat (siehe [readme-old.md](./readme-old.md)) bleibt
unverändert bestehen; die Matrix-Auslagerung ersetzt ihn in dieser Phase noch
nicht.

## Architektur

```
Sinclear-Nutzer registriert sich (Discord OAuth)
        │
        ▼
DiscordOAuthService::processRegistrationCallback()
        └─► MatrixSyncService::enqueueCreate(userId)   (Outbox: type=create)

ProfileService::updateProfile() — displayName geändert
        └─► MatrixSyncService::enqueueDisplayName(userId, name)  (Outbox: type=displayname)
        │
        ▼
┌───────────────────────────────────┐
│ MySQL                             │
│  MatrixAccount       (Ist/Soll)   │
│  MatrixSyncOperation (Outbox)     │
└───────────────────────────────────┘
        ▲
        │  MatrixSyncTask (Cron, alle 5 Min)
        │   – Reconciliation (Drift erkennen)
        │   – Outbox verarbeiten (Retry/Backoff)
        ▼
┌────────────────────────────────────────┐
│ MatrixClient (Guzzle)                  │
│  POST /_matrix/client/v3/register (AS) │
│  PUT  /_matrix/client/v3/profile/...   │
└────────────────────────────────────────┘
        ▼
Continuwuity (Homeserver, Application Service)
```

**Kernprinzip:** Die Datenbank ist die Wahrheit darüber, was *gewollt* ist
(`MatrixAccount`, `MatrixSyncOperation`) und was *bereits umgesetzt* wurde. Der
Cron-Task ist nur der Motor, der die Differenz abarbeitet. Der Sync ist damit
selbstheilend: bleibt der Homeserver offline, bleiben Operationen `pending` und
werden beim nächsten Lauf erneut versucht.

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `MatrixAccount` | Eine Zeile pro Nutzer: `localpart`, `matrixUserId`, verschlüsseltes Passwort, `displayNameSynced` (zuletzt erfolgreich übernommener Name) |
| `MatrixSyncOperation` | Outbox: eine Zeile pro Zustandsänderung mit Matrix-Bezug (`type`: `create`/`displayname`), Retry-/Backoff-Metadaten, Status (`pending`/`done`/`failed`) |

- `localpart` ist deterministisch: `sb_` + UUID7 **ohne Bindestriche**
  (`sb_0192f3ab…`). Damit steht `matrixUserId` (`@sb_…:server`) schon **vor**
  dem Register-Aufruf fest — der `create`-Schritt ist idempotent.
- **Status-Semantik:** `pending` = noch nicht (vollständig) umgesetzt (inkl.
  aller Retries), `done` = erfolgreich, `failed` = permanent fehlgeschlagen
  (wird nicht mehr automatisch versucht, bleibt für Admin/Log sichtbar).

## Sync-Logik

### Phase A — Reconciliation (Drift erkennen, selbstheilend)
1. Für alle Nutzer ohne `MatrixAccount` (oder `matrixUserId IS NULL`) ohne
   bereits `pending` `create`-Operation → `MatrixAccount`-Zeile anlegen
   (Passwort generieren + verschlüsseln) und `create`-Operation einreihen.
   Zugleich Backfill für Bestandsnutzer.
2. Für alle aktiven Accounts mit `User.displayName <> MatrixAccount.displayNameSynced`
   und ohne `pending` `displayname`-Operation → `displayname`-Operation einreihen.

### Phase B — Outbox verarbeiten (Retry/Backoff)
- `create`: `POST /_matrix/client/v3/register` mit `m.login.application_service`.
  Erfolg **oder** `M_USER_IN_USE` (idempotenter Retry) → `matrixUserId` setzen,
  `done`. Danach wird der initiale `displayName` über eine neue
  `displayname`-Operation gesetzt.
- `displayname`: `PUT /_matrix/client/v3/profile/{mxid}/displayname` mit
  `?user_id=` (AS-Impersonation). Erfolg → `displayNameSynced` setzen, `done`.
- **Fehler:** transient (Netzwerk/Timeout/5xx/429/`M_UNKNOWN`/`M_LIMIT_EXCEEDED`)
  → `attempts++`, Backoff `min(5min × 2^attempts, 60min)` + Jitter, bleibt
  `pending`. Permanent (4xx außer `M_USER_IN_USE`) → `failed`.
- Batch-Limit (Standard 50) pro Lauf als Laufzeitschutz.

## Konfiguration

`.env` (siehe `.env.example`):

```dotenv
MATRIX_HOMESERVER_URL=https://matrix.example.tld
MATRIX_SERVER_NAME=matrix.example.tld
MATRIX_AS_TOKEN=
MATRIX_HS_TOKEN=
MATRIX_SENDER_LOCALPART=_sinclearbeyond_bot
MATRIX_NAMESPACE_PREFIX=sb_
MATRIX_PASSWORD_KEY=
MATRIX_SYNC_BATCH_SIZE=50
```

- Das einmalig generierte Matrix-Passwort (`bin2hex(random_bytes(16))`) wird mit
  `sodium_crypto_secretbox()` unter `MATRIX_PASSWORD_KEY` (32 Byte, hex)
  verschlüsselt abgelegt. Es wird **nicht** an Nutzer/Clients ausgeliefert und
  hat keinen Ändern-/Reset-Flow.
- Solange `MATRIX_HOMESERVER_URL`, `MATRIX_AS_TOKEN`, `MATRIX_SERVER_NAME` oder
  `MATRIX_PASSWORD_KEY` fehlen, ist der Sync inaktiv (No-op) — die übrigen
  Flows (Registrierung, Profil) bleiben unbeeinflusst.

## Application Service

- **Transaktions-Endpunkt (Stub):** `public/matrix/as-transactions.php`
  (`/matrix/as-transactions` via `.htaccess`-Rewrite). Validiert ausschließlich
  das `hs_token` und antwortet mit `{}` — eingehende Events werden in dieser
  Phase nicht verarbeitet.
- **Registrierung:** im `#admins`-Raum per `!admin appservices register` mit dem
  Registration-YAML (Vorlage: `docs/chat/continuwuity_docker_config/registration.yaml`,
  Docker-Setup: `docs/chat/continuwuity_docker_config/`).
- **Sicherheit:** `as_token`/`hs_token` nur in `.env`; exklusiver Namensraum
  `sb_` stellt sicher, dass nur der AS diese Accounts anlegt (öffentliche
  Registrierung gesperrt).

## Sicherheit

- `MATRIX_AS_TOKEN`/`MATRIX_HS_TOKEN` ausschließlich in `.env` (durch
  `.htaccess`/`FilesMatch` geschützt), nie im Git.
- Generiertes Passwort verschlüsselt (`sodium_crypto_secretbox`).
- AS-Endpunkt validiert `hs_token` strikt (`hash_equals`).

## Cron

- `matrix_sync` (300 s): `MatrixSyncTask` → `MatrixSyncService::reconcile()` +
  `processDueOperations()`. Siehe [../CRON.md](../CRON.md).

## Admin-Dashboard

`/api/v2/admin/matrix` — Übersicht über `MatrixAccount`-Zeilen, offene/felhlgeschlagene
`MatrixSyncOperation`-Einträge, Aktion „Neu versuchen" (setzt `failed`→`pending`,
`nextAttemptAt=NULL`) und „Alle reconciliieren".

## Bewusst NICHT enthalten (spätere Schritte)

Räume/Kontakte, Nachrichten, E2EE, eingehende Events, Avatar-Sync, Passwort-
Anzeige/-Änderung/-Reset, Account-Deaktivierung/-Löschung, Flutter-Integration,
Push/Notification-Integration (kein neuer Notification-Typ).
