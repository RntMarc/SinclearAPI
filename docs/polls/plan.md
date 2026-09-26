# Implementation Plan – Umfragen (Polls)

> **Status:** Implementiert (2026-09-27) – siehe Abschluss-Review am Ende.
> **Bereich:** `polls` – Formulare, Terminfindung, Abstimmung
> **API-Prefix:** `/api/v2/polls`

## WICHTIGE REGELN FÜR DEN AUSFÜHRENDEN AGENTEN

> **ACHTUNG AGENT:**
> 1. Führe alle Schritte **einzeln und nacheinander** in der angegebenen Reihenfolge aus.
> 2. Teste und verifiziere das Ergebnis nach **jedem einzelnen Schritt**.
> 3. Dokumentiere Änderungen und Testergebnisse kurz nach jedem Schritt.
> 4. Hake die Checkbox `[ ]` -> `[x]` **sofort nach erfolgreichem Abschluss und Verifikation** jedes Schrittes ab, bevor du zum nächsten übergehst.
> 5. Befolge durchgehend `AGENTS.md`: `openapi.yaml` aktuell halten, `docs/` aktualisieren, Policies für alle Endpunkte, `.htaccess` prüfen, lokale Prüfungen (`php -l`, `vendor/bin/phpstan`, DB-freie Unit-Tests) voranstellen. `update.sh` NIE ungefragt ausführen, keine SSH-Verbindung.
> 6. Bei jeder Änderung an Notification-Typen: `docs/notifications/types.md` **und** `docs/notifications/readme.md` + `NotificationPreferenceService` aktualisieren.

---

## Ziel & Beschreibung

Die API erhält einen neuen Bereich **Umfragen** mit drei Arten, die im Client gebündelt angezeigt werden, sich aber innerlich stark unterscheiden:

- **Formular (`form`)** – vergleichbar Google/Microsoft Forms: beliebig viele Fragen, Nutzer geben Antworten ein.
- **Terminfindung (`appointment`)** – vergleichbar Doodle/Framadate: vorgegebene Terminvorschläge, Verfügbarkeits-Abstimmung (`yes`/`maybe`/`no`), optionale Gegenvorschläge, Einmal-Abstimmung mit nachträglicher Änderung.
- **Abstimmung (`vote`)** – anonymes Voting: nur die Teilnahme wird erfasst (Doppelwahl verhindern), Stimmen werden gezählt; **keine** Verknüpfung Nutzer ↔ Antwort; einmalig, keine nachträgliche Änderung.

---

## 1. Fixierte Entscheidungen

| Thema | Entscheidung |
|---|---|
| API-Prefix | `/api/v2/polls` (gemeinsamer Bereich, `type` als Diskriminator) |
| DB-Modell | Gemeinsamer `Poll`-Header + gemeinsame `PollQuestion`/`PollOption`/`PollInvite` + typspezifische Antwort-/Stimmtabellen |
| Legacy | `Poll*`-Tabellen per Migration **droppen** und neu anlegen |
| Fragetypen | alle: `text`, `textarea`, `number`, `email`, `coordinates`, `date`, `datetime`, `url`, `phone`, `single_choice`, `multiple_choice`, `boolean`, `rating` |
| Formular-Modus | pro Umfrage konfigurierbar: `single` (einmal, editierbar bis Deadline) oder `multiple` |
| Zugriff | pro Umfrage konfigurierbar: `invited` (nur Eingeladene + Ersteller) oder `all_users` (alle Angemeldeten; keine Link-/ID-Freigabe) |
| Formular-Ergebnisse | konfigurierbar: `creator` (nur Ersteller/Admin) oder `participants` |
| Terminfindung | alle Teilnehmer sehen alle Stimmen (Doodle-artig); Einmal-Abstimmung pro Nutzer/Option, nachträglich änderbar; Gegenvorschläge erlaubt (wenn aktiviert); Ersteller legt finalen Termin fest |
| Anonyme Abstimmung | gehashte Teilnehmerkennung `participantHash = HMAC-SHA256(pollId:userId, secret)` in der Stimmtabelle, **kein** `userId`; einmalig, nicht änderbar |
| Abstimmungsergebnis | erst nach `closed`/Deadline sichtbar |
| Notifications | alle vier: Einladung, Gegenvorschlag, festgelegt/geschlossen, Deadline-Erinnerung |
| Langtext | Plain Text mit Zeilenumbrüchen (kein HTML/Markdown-Rendering) |
| Frist | `closesAt` optional + `status` `open`/`closed` |
| Realtime | nur REST, kein Centrifugo |

---

## 2. Datenbankschema (neue Migration)

Datei: `database/migrations/20260927000000_polls.sql`

Vor den `CREATE`-Anweisungen `SET FOREIGN_KEY_CHECKS=0`, dann Legacy-Tabellen in der Reihenfolge `PollVote`, `PollInvite`, `PollOption`, `PollQuestion`, `Poll` droppen, danach neu anlegen und `FOREIGN_KEY_CHECKS=1` wiederherstellen.

### 2.1 Gemeinsamer Header

```sql
CREATE TABLE Poll (
  id varchar(191) NOT NULL,
  type enum('form','appointment','vote') NOT NULL,
  creatorId varchar(191) NOT NULL,
  title varchar(255) NOT NULL,
  description text NULL,
  status enum('open','closed') NOT NULL DEFAULT 'open',
  closesAt datetime(3) NULL,
  accessMode enum('invited','all_users') NOT NULL DEFAULT 'invited',
  submissionMode enum('single','multiple') NOT NULL DEFAULT 'single',      -- nur form
  resultsVisibility enum('creator','participants') NOT NULL DEFAULT 'creator', -- nur form
  allowCounterProposals tinyint NOT NULL DEFAULT 0,                          -- nur appointment
  finalizedOptionId varchar(191) NULL,                                      -- nur appointment
  reminderSentAt datetime(3) NULL,                                          -- Cron
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_poll_creator (creatorId),
  KEY idx_poll_type_status (type,status),
  KEY idx_poll_due (status,closesAt),
  CONSTRAINT fk_poll_creator FOREIGN KEY (creatorId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> `finalizedOptionId` bewusst ohne FK (Kreisabhängigkeit Poll↔PollOption); Integrität im Service.

### 2.2 Gemeinsame Kindtabellen

```sql
CREATE TABLE PollQuestion (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL,
  type varchar(32) NOT NULL, title text NOT NULL, description text NULL,
  isRequired tinyint NOT NULL DEFAULT 0, position smallint NOT NULL DEFAULT 0,
  config json NULL, createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY idx_question_poll (pollId,position),
  CONSTRAINT fk_question_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE
);

CREATE TABLE PollOption (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL, questionId varchar(191) NULL,
  label text NULL, allDay tinyint NOT NULL DEFAULT 0,
  timezone varchar(64) NULL, startAt datetime(3) NULL, endAt datetime(3) NULL,
  startDate date NULL, endDate date NULL,
  isCounterProposal tinyint NOT NULL DEFAULT 0, proposedBy varchar(191) NULL,
  position smallint NOT NULL DEFAULT 0, createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY idx_option_poll (pollId), KEY idx_option_question (questionId),
  CONSTRAINT fk_option_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_option_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE,
  CONSTRAINT fk_option_proposer FOREIGN KEY (proposedBy) REFERENCES User(id) ON DELETE SET NULL
);

CREATE TABLE PollInvite (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL, userId varchar(191) NOT NULL,
  isIndispensable tinyint NOT NULL DEFAULT 0, createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_invite_poll_user (pollId,userId),
  CONSTRAINT fk_invite_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
);
```

### 2.3 Typspezifisch: Formular

```sql
CREATE TABLE PollResponse (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL, userId varchar(191) NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY idx_response_poll_user (pollId,userId),
  CONSTRAINT fk_response_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_response_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
);

CREATE TABLE PollAnswer (
  id varchar(191) NOT NULL, responseId varchar(191) NOT NULL, questionId varchar(191) NOT NULL,
  value mediumtext NULL, createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY idx_answer_response (responseId),
  CONSTRAINT fk_answer_response FOREIGN KEY (responseId) REFERENCES PollResponse(id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE
);
```

`PollAnswer.value`-Semantik: `text`/`textarea`/`email`/`url`/`phone` als String; `number`/`rating` als Zahl; `boolean` als `"1"/"0"`; `coordinates` als `"lat,lon"`; `date`/`datetime` als String; `single_choice` als eine Option-ID; `multiple_choice` als JSON-Array von Option-IDs.

### 2.4 Typspezifisch: Terminfindung

```sql
CREATE TABLE PollAvailabilityVote (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL, optionId varchar(191) NOT NULL,
  userId varchar(191) NOT NULL, availability enum('yes','maybe','no') NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_avail_poll_option_user (pollId,optionId,userId),
  KEY idx_avail_poll (pollId),
  CONSTRAINT fk_avail_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_avail_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE,
  CONSTRAINT fk_avail_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
);
```

Gegenvorschläge = `PollOption` mit `isCounterProposal=1` und `proposedBy`.

### 2.5 Typspezifisch: Anonyme Abstimmung

```sql
CREATE TABLE PollVote (
  id varchar(191) NOT NULL, pollId varchar(191) NOT NULL, optionId varchar(191) NOT NULL,
  participantHash char(64) NOT NULL, createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_vote_poll_hash_option (pollId,participantHash,optionId),
  KEY idx_vote_poll_option (pollId,optionId), KEY idx_vote_hash (pollId,participantHash),
  CONSTRAINT fk_pollvote_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_pollvote_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> Kein `userId` – Anonymität. Einmaligkeit: vor dem Insert prüfen, ob `(pollId, participantHash)` bereits existiert → sonst `already_voted`.

---

## 3. Frage-/Options-Modell

`PollQuestion.type` steuert die Validierung; `config` (JSON) hält typspezifische Grenzen, damit Erweiterung ohne Schemaänderung möglich ist.

| Type | config | Validierung |
|---|---|---|
| `text`, `textarea` | `minLength`, `maxLength` (Default = DB-Cap `mediumtext`) | Länge |
| `number` | `min`, `max`, `integerOnly` | Zahl, Bereich |
| `email` | – | `filter_var(...FILTER_VALIDATE_EMAIL)` |
| `coordinates` | `requireBothFields` | `lat ∈ [-90,90]`, `lon ∈ [-180,180]`, zwei Werte |
| `date` | `minDate`, `maxDate` | `DateTimeValue::parseDate` |
| `datetime` | `timezone` (IANA) | RFC 3339 mit Offset (AGENTS Date/Time Convention) |
| `url` | `allowedSchemes` | `filter_var(...FILTER_VALIDATE_URL)` |
| `phone` | – | E.164-nahe Regex |
| `single_choice` | `allowOther` | Option-ID ∈ PollOption der Frage |
| `multiple_choice` | `minSelected`, `maxSelected` | Teilmenge der Option-IDs |
| `boolean` | – | `0/1` |
| `rating` | `min`, `max`, `step`, `labels` | Zahl im Bereich |

Zentrale Klasse `src/Services/Poll/PollAnswerValidator.php` (unit-testbar, ohne DB).

---

## 4. API-Endpunkte (`/api/v2/polls`, alle JWT)

| Methode | Pfad | Zweck | Berechtigung |
|---|---|---|---|
| POST | `/polls` | Umfrage anlegen (`type` + typgerechtes Payload, atomar mit Fragen/Optionen/Invites) | angemeldet |
| GET | `/polls` | Sichtbare Umfragen (Filter `type`,`status`; paginiert) | angemeldet |
| GET | `/polls/{id}` | Detail inkl. Fragen/Optionen, eigener Teilnahmestatus | sichtbar |
| PATCH | `/polls/{id}` | Meta/Settings ändern | Ersteller/Admin |
| DELETE | `/polls/{id}` | Löschen | Ersteller/Admin |
| POST | `/polls/{id}/close` | Manuell schließen | Ersteller/Admin |
| GET | `/polls/{id}/invites` | Einladungen listen | Ersteller/Admin |
| POST | `/polls/{id}/invites` | Nutzer einladen | Ersteller/Admin |
| DELETE | `/polls/{id}/invites/{userId}` | Einladung entfernen | Ersteller/Admin |
| GET | `/polls/{id}/responses` | Formular-Antworten (Roh) | Ersteller/Admin, oder Teilnehmer bei `participants` |
| POST | `/polls/{id}/responses` | Formular absenden | sichtbar + offen + Modus |
| GET | `/polls/{id}/responses/me` | Eigene Antwort | Eigentümer |
| PATCH | `/polls/{id}/responses/{responseId}` | Antwort ändern | Eigentümer, `single`, offen |
| POST | `/polls/{id}/options` | Gegenvorschlag (Terminfindung) | sichtbar + `allowCounterProposals` |
| DELETE | `/polls/{id}/options/{optionId}` | Eigenen Gegenvorschlag löschen | Vorschlagender/Ersteller/Admin |
| GET | `/polls/{id}/availability` | Alle Verfügbarkeiten | sichtbar |
| PUT | `/polls/{id}/availability` | Eigene Verfügbarkeiten setzen (Upsert) | sichtbar + offen |
| POST | `/polls/{id}/finalize` | Termin festlegen | Ersteller/Admin |
| GET | `/polls/{id}/vote-status` | Ob Nutzer bereits abgestimmt hat | sichtbar |
| POST | `/polls/{id}/vote` | Einmalig abstimmen (`optionIds[]`) | sichtbar + offen |
| GET | `/polls/{id}/results` | Ergebnis-Zählungen | erst nach `closed`; Ersteller/Admin |

Antworten im bestehenden Stil (`ResponseFactory::json/paginated/noContent`), Fehlercodes über Controller-`ERROR_MAP` (`not_found`, `forbidden`, `poll_closed`, `already_voted`, `already_responded`, `invalid_answer`, `results_hidden`, `counter_proposals_disabled`, `invalid_type`, `invalid_config`, …).

---

## 5. Neue Dateien / Änderungen

**Repositories** (`src/Repository/`):
`PollRepository`, `PollQuestionRepository`, `PollOptionRepository`, `PollInviteRepository`, `PollResponseRepository`, `PollAnswerRepository`, `PollAvailabilityVoteRepository`, `PollVoteRepository`.

**Services** (`src/Services/`):
`PollService` (shared: create/list/get/update/close/delete/invites + Orchestrierung), `PollFormService`, `PollAppointmentService`, `PollVoteService`, `PollNotificationService`, `Poll/PollAnswerValidator`.

**Support** (`src/Support/`): `PollAnonymity` (HMAC).

**Controller** (`src/Controllers/`):
`PollController` (shared + Formular), `PollAppointmentController`, `PollVoteController`.

**Policy** (`src/Security/Policy/`): `PollPolicy` (`canView`, `canManage`, `canRespond`, `canEditResponse`, `canSeeResults`, `canAddCounterProposal`, `canFinalize`, `canVote`).

**Wiring:** alle Klassen in `config/dependencies.php`; Routen in `config/routes.php` in einer `/polls`-Gruppe mit `AuthenticationMiddleware`.

**Config:** `.env.example` `POLL_ANONYMITY_SECRET`; `config/settings.php` Block `polls`; `src/Application/Settings.php` Parameter `public array $polls = []`.

---

## 6. Notifications

- `NotificationService::CONTENT_TEMPLATES` + `normalizeData()`-Cases und Relations:
  - `poll_invite` → `poll` (Poll), `inviter` (User)
  - `poll_counter_proposal` → `poll` (Poll), `proposer` (User), `option` (PollOption)
  - `poll_finalized` → `poll` (Poll), `finalized_option` (PollOption, optional)
  - `poll_deadline_reminder` → `poll` (Poll)
- `NotificationPreferenceService::KNOWN_TYPES` um die vier Typen ergänzen; `CUSTOMIZABLE_TYPES` für alle vier mit `['relation' => 'poll', 'dataKey' => 'pollIds']` (Denylist-Semantik bleibt gewahrt).
- Empfängerlogik: Einladung an Eingeladene; Gegenvorschlag an Ersteller + übrige Teilnehmer (kein Self); `poll_finalized`/`closed` an alle Teilnehmer; Reminder an Teilnehmer ohne Antwort.
- Dedupe: Reminder `dedupeKey = "poll:<pollId>:deadline"`; Einladung falls nötig pro Nutzer.
- **Doku-Pflicht:** `docs/notifications/types.md`, `docs/notifications/readme.md`, `openapi.yaml` (Preferences-Enum + `Notification.type`-Enum + Beschreibung).

---

## 7. Cron

Neuer Task `src/Services/Cron/Tasks/PollDeadlineTask.php`, registriert in `bin/cron.php`:
1. Offene Polls mit `closesAt < NOW()` → `status='closed'` (Ergebnisse werden sichtbar).
2. Offene Polls, deren `closesAt` im Erinnerungsfenster liegt (z. B. 24 h) und deren Teilnehmer noch nicht geantwortet/abgestimmt haben → `poll_deadline_reminder`.

Intervall z. B. 3600 s. **`docs/CRON.md`** aktualisieren (Übersichtstabelle + Detail).

---

## 8. Admin-Dashboard

- Routen: `GET /admin/polls`, `GET /admin/polls/json`, `GET /admin/polls/{id}`, `POST /admin/polls/{id}/close`, `DELETE /admin/polls/{id}`.
- `AdminController`-Methoden + Templates `templates/admin/polls.php`, `templates/admin/poll_detail.php`; Nav-Link in `templates/admin/layout.php`; Dashboard-Statistik optional.
- Bestehende Poll-Referenzen (`adminNotificationsJson` `SELECT id,title FROM Poll`, OpenAPI `/admin/notifications/json` `polls`) bleiben nach Recreate kompatibel.
- **`docs/admin/readme.md`** ergänzen.

---

## 9. Dokumentation & Spec

- **Neu:** `docs/polls/readme.md` (maßgebliche Modul-Doku: Tabellen, Typen, Endpunkte, Zugriffs-/Abstimmungsregeln, Anonymität), `docs/polls/types.md` (Fragetypen + Konfig).
- **Aktualisieren:** `openapi.yaml` (Tag `[Polls]`, alle Pfade + Schemas, Preferences-Enum, Admin-Endpunkte), `docs/notifications/types.md`, `docs/notifications/readme.md`, `docs/CRON.md`, `docs/admin/readme.md`.
- MCP: neue Topics `polls`, `polls/types`, `polls/plan` werden automatisch gescannt; `MCP.md` benötigt **keine** Änderung (dynamisches `enum`), wird aber verifiziert.
- `.htaccess`: keine neue Freigabe nötig (`database/`, `docs/`, `src/`, `templates/` sind bereits gesperrt) – wird gemäß AGENTS nur geprüft.

---

## 10. Tests

- **Unit (lokal, ohne DB):** `PollAnswerValidatorTest` (alle Typen/Grenzen), `PollPolicyTest`, `PollAnonymityTest` (Determinismus, keine Klartext-User-ID), `PollNotificationDataTest`.
- **Integration (Server, DB):** Formular-Flow (single/multiple, Ergebnisse-Sichtbarkeit), Terminfindung (Verfügbarkeit upsert, Gegenvorschlag, Finalisierung), Abstimmung (einmalig, keine Änderung, Anonymität, Ergebnisse erst nach `closed`), Zugriffsmodi (`invited` vs `all_users`).
- Lokal nur `php -l`, `vendor/bin/phpstan`, DB-freie Unit-Tests (AGENTS).

---

## Reihenfolge zur Implementierung (Phasen & Arbeitsschritte)

### Phase 1: Datenbank-Migration

- [x] 1.1 **Migrationsskript erstellen** (`database/migrations/20260927000000_polls.sql`): `FOREIGN_KEY_CHECKS=0`, Legacy-Tabellen droppen (`PollVote`, `PollInvite`, `PollOption`, `PollQuestion`, `Poll`), neue Tabellen laut Abschnitt 2 anlegen, `FOREIGN_KEY_CHECKS=1`.
  - Erledigt: Alle 8 neuen Tabellen + Drops angelegt. Verifikation: `php -l` n/a (SQL), Struktur manuell geprüft.
- [x] 1.2 **Skript reviewen**: FKs konsistent, Kollation `utf8mb4_unicode_ci`, `datetime(3)`, Indizes vorhanden. Hinweis im Header ergänzen (nicht blind rerun-fähig, Legacy-Daten gehen verloren).
  - Verifikation: FK-Reihenfolge (User-Parent existiert), `finalizedOptionId` bewusst ohne FK, Alle Tabellen `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, Header-Hinweis vorhanden.

### Phase 2: Datenbankschicht (Repositories & DI)

- [x] 2.1 **Repositories anlegen** (`src/Repository/PollRepository.php`, `PollQuestionRepository.php`, `PollOptionRepository.php`, `PollInviteRepository.php`, `PollResponseRepository.php`, `PollAnswerRepository.php`, `PollAvailabilityVoteRepository.php`, `PollVoteRepository.php`) – Muster wie `FeedbackSuggestionRepository` (`final readonly`, PDO, `Uuid::uuid7()`).
  - Verifikation: `php -l` auf allen 8 Dateien ohne Fehler.
- [x] 2.2 **DI verdrahten** in `config/dependencies.php` (alle Repositories `autowire()`).
  - Verifikation: `php -l config/dependencies.php` ohne Fehler.

### Phase 3: Validierung & Support

- [x] 3.1 **`PollAnswerValidator`** (`src/Services/Poll/PollAnswerValidator.php`) für alle 13 Fragetypen (Abschnitt 3).
  - Verifikation: `php -l` fehlerfrei.
- [x] 3.2 **`PollAnonymity`** (`src/Support/PollAnonymity.php`): `participantHash = HMAC-SHA256(pollId . ':' . userId, secret)`; Secret aus `settings.polls.anonymity_secret`.
  - Verifikation: `php -l` fehlerfrei.
- [x] 3.3 **Config ergänzen**: `.env.example` `POLL_ANONYMITY_SECRET`, `config/settings.php` Block `polls`, `src/Application/Settings.php` Parameter `public array $polls = []`.
  - Verifikation: `php -l config/settings.php` und `src/Application/Settings.php` fehlerfrei.
- [x] 3.4 **Unit-Tests** `PollAnswerValidatorTest`, `PollAnonymityTest` schreiben und lokal ausführen (`vendor/bin/phpunit --filter`); `php -l` und `vendor/bin/phpstan` grün.
  - Verifikation: `vendor/bin/phpunit --filter 'PollAnswerValidatorTest|PollAnonymityTest'` → OK (47 Tests, 50 Assertions).

### Phase 4: Services & Policies

- [x] 4.1 **`PollPolicy`** (`src/Security/Policy/PollPolicy.php`) mit allen `can*`-Methoden.
  - Verifikation: `php -l` fehlerfrei; `PollPolicyTest` grün.
- [x] 4.2 **`PollService`** (shared: create/list/get/update/close/delete/invites; Orchestrierung der typspezifischen Sub-Services; Anwendung der Date/Time-Convention für `PollOption`-Zeitfelder via `DateTimeValue`).
  - Verifikation: `php -l` fehlerfrei.
- [x] 4.3 **`PollFormService`** (responses/answers, `submissionMode`, `resultsVisibility`, Validierung über `PollAnswerValidator`).
  - Verifikation: `php -l` fehlerfrei.
- [x] 4.4 **`PollAppointmentService`** (availability-Upsert, Gegenvorschläge, Finalisierung).
  - Verifikation: `php -l` fehlerfrei.
- [x] 4.5 **`PollVoteService`** (einmalige anonyme Stimme, `vote-status`, Ergebnisse nach `closed`).
  - Verifikation: `php -l` fehlerfrei.
- [x] 4.6 **Unit-Tests** `PollPolicyTest` + ggf. Service-Tests mit Fake-Repositories (DB-frei).
  - Verifikation: `vendor/bin/phpunit --filter 'PollPolicyTest'` grün (14 Tests). Service-Tests mit Fake-Repos zurückgestellt (DB-lastige Flows folgen als Integrationstests in Phase 10).

### Phase 5: Controller & Routing

- [x] 5.1 **`PollController`** (shared + Formular), **`PollAppointmentController`**, **`PollVoteController`** (`final readonly`, `requireUser`, `ERROR_MAP`, `ResponseFactory`).
  - Verifikation: `php -l` fehlerfrei; phpstan (Level 5) ohne Fehler.
- [x] 5.2 **Routen** in `config/routes.php` unter `/polls` mit `AuthenticationMiddleware` (statische Pfade vor `{id}`).
  - Verifikation: `php -l config/routes.php` fehlerfrei; `/responses/me` vor `/responses/{responseId}`.
- [x] 5.3 **DI** für Controller + Services in `config/dependencies.php`; `php -l` auf allen neuen Dateien.
  - Verifikation: Container baut, `PollAnonymity`/`PollAnswerValidator`/`PollPolicy` auflösbar; `php -l` fehlerfrei.

### Phase 6: Notifications

- [x] 6.1 **`NotificationService`** erweitern: `CONTENT_TEMPLATES` + `normalizeData()`-Cases + `normalizePoll*Data()`-Methoden (Abschnitt 6).
  - Verifikation: `php -l` fehlerfrei; `PollNotificationDataTest` grün (8 Tests).
- [x] 6.2 **`NotificationPreferenceService`** erweitern: `KNOWN_TYPES` + `CUSTOMIZABLE_TYPES` (`pollIds`).
  - Verifikation: `php -l` fehlerfrei.
- [x] 6.3 **`PollNotificationService`** (`src/Services/PollNotificationService.php`) für Einladung, Gegenvorschlag, finalisiert/geschlossen, Reminder.
  - Verifikation: `php -l` fehlerfrei.
- [x] 6.4 **Doku + Spec**: `docs/notifications/types.md`, `docs/notifications/readme.md`, `openapi.yaml` (Preferences-Enum, `Notification.type`-Enum).
  - Verifikation: Typen/Tabellen ergänzt; OpenAPI-Enums (Notification.type, Preferences) und `customData`-Beschreibung erweitert.
- [x] 6.5 **Tests** `NotificationPreferenceServiceTest`/`NotificationServiceTest` um die neuen Typen erweitern; lokal ausführen.
  - Verifikation: Tests ergänzt; DB-abhängige Tests laufen auf dem Server, DB-freier `PollNotificationDataTest` lokal grün.

### Phase 7: Cron

- [x] 7.1 **`PollDeadlineTask`** (`src/Services/Cron/Tasks/PollDeadlineTask.php`): auto-close + Reminder.
  - Verifikation: `php -l` und phpstan (Level 5) fehlerfrei.
- [x] 7.2 **Registrieren** in `bin/cron.php`.
  - Verifikation: `php -l bin/cron.php` fehlerfrei.
- [x] 7.3 **`docs/CRON.md`** aktualisieren (Übersichtstabelle + Detail).
  - Verifikation: Zeile 7 + Detailabschnitt „Polls Deadline" ergänzt.

### Phase 8: Admin-Dashboard

- [x] 8.1 **`AdminController`**-Methoden + Routen (`/admin/polls`, `/json`, `/{id}`, `/{id}/close`, DELETE).
  - Verifikation: `php -l` AdminController + routes fehlerfrei.
- [x] 8.2 **Templates** `templates/admin/polls.php`, `poll_detail.php` + Nav-Link in `templates/admin/layout.php`.
  - Verifikation: Templates angelegt, Nav-Link ergänzt.
- [x] 8.3 **`docs/admin/readme.md`** aktualisieren.
  - Verifikation: Seitenabschnitt + Endpoint-Tabelle ergänzt.
- [x] 8.4 Prüfen: bestehende Poll-Referenzen (`adminNotificationsJson`, OpenAPI `/admin/notifications/json`) weiterhin kompatibel.
  - Verifikation: Neue `Poll`-Tabelle enthält weiterhin `id` und `title`; `SELECT id, title FROM Poll` in `adminNotificationsJson` bleibt gültig. `/admin/polls/json` vor `/admin/polls/{id}` registriert.

### Phase 9: Dokumentation & OpenAPI

- [x] 9.1 **`docs/polls/readme.md`** (maßgebliche Modul-Doku) und **`docs/polls/types.md`** anlegen.
  - Verifikation: Beide Dateien angelegt und inhaltlich vollständig (Tabellen, Endpunkte, Fragetypen, Anonymität).
- [x] 9.2 **`openapi.yaml`** vollständig aktualisieren: Tag `[Polls]`, alle Pfade, Schemas (`Poll`, `PollQuestion`, `PollOption`, `PollInvite`, `PollResponse`, `PollAnswer`, `PollAvailabilityVote`, `PollVote`, Request-Bodies, Enums).
  - Verifikation: YAML valide (`yaml.safe_load`), alle `$ref` auflösbar (0 fehlend), 22 Poll-Pfade + Admin-Pfade + 30 Poll-Schemas ergänzt.
- [x] 9.3 **MCP prüfen**: `polls`, `polls/types`, `polls/plan` als Topics erreichbar; `MCP.md` konsultieren.
  - Verifikation: `DocumentationProvider::availableTopics()` enthält alle drei; `polls/types` lösbar. `MCP.md` benötigt keine Änderung (dynamisches `enum`).
- [x] 9.4 **`.htaccess` prüfen**: keine neuen Freigaben nötig; sensible Dateien weiterhin gesperrt.
  - Verifikation: `docs/`, `database/`, `src/`, `templates/` etc. weiterhin `[F,L]`; `.sql/.yaml/.md` per FilesMatch gesperrt. Keine Änderung nötig.

### Phase 10: Integrationstests & Abschluss

- [x] 10.1 **Integrationstests** schreiben (Formular-, Terminfindungs-, Abstimmungs-Flow, Zugriffsmodi, Anonymität) – laufen erst auf dem Server (AGENTS).
  - Verifikation: `tests/Integration/PollIntegrationTest.php` angelegt (`php -l` fehlerfrei); deckt Zugriffsmodi, Formular (single + Sichtbarkeit), Terminfindung (Upsert, Gegenvorschlag, Finalisierung) und anonyme Abstimmung (einmalig, Ergebnisse erst nach `closed`, kein `userId`) ab. Ausführung nur auf dem Server.
- [x] 10.2 **Abschluss-Review**: alle Checkboxen, `openapi.yaml`-Konsistenz, Doku-Vollständigkeit, `php -l`/`vendor/bin/phpstan` grün.
  - Verifikation: alle Checkboxen gesetzt; `openapi.yaml` YAML-valide + alle `$ref` auflösbar; DB-freie Unit-Tests grün (78 Tests, 82 Assertions: `PollAnswerValidatorTest`, `PollAnonymityTest`, `PollPolicyTest`, `PollNotificationDataTest`); `php -l` und `phpstan` (Level 5) auf allen neuen Poll-Dateien fehlerfrei.
- [x] 10.3 Dieses Dokument auf finalen Stand bringen (Status → „Implementiert", Datum, Abweichungen festhalten).
  - Verifikation: Status/Abweichungen unten ergänzt.

---

## Implementierungs-Abschluss

> **Status:** Implementiert
> **Datum:** 2026-09-27

### Abweichungen / Hinweise

- **Service-Tests mit Fake-Repositories (4.6):** Statt aufwendiger Fakes wurden `PollPolicyTest` (DB-frei) und `PollNotificationDataTest` (Reflection, DB-frei) plus die Integrationstests (10.1) umgesetzt. Die DB-lastigen Flows laufen als Integrationstests auf dem Server.
- **`PollAnswerValidator` Choice-Typen:** Die erlaubten Option-IDs werden dem Validator vom Service übergeben (`validate($question, $value, $optionIds)`), damit er DB-frei bleibt.
- **Längen-/Wertefelder in `PollAnswer.value`:** `datetime` wird als UTC-DATETIME-String gespeichert, `multiple_choice` als JSON-Array-String — konsistent zur Validator-Normalisierung.
- **Auto-Close-Benachrichtigung:** Der Cron-Task `polls_deadline` benachrichtigt Teilnehmer beim Auto-Close via `poll_finalized` (gleicher Typ wie manuelles Schließen/Finalisieren).
- **`finalize` (Terminfindung):** setzt `finalizedOptionId` und schließt die Umfrage (Status `closed`), danach `poll_finalized`-Notification.
- **phpstan:** Es existiert keine projektweite `phpstan.neon`; geprüft wurde mit `--level=5` auf allen neu angelegten/geänderten Poll-Dateien. Vorbestehende Hinweise in `NotificationPreferenceService`/`NotificationService` wurden nicht angefasst.
