# Umfragen (Polls)

Modul für Formulare, Terminfindungen und anonyme Abstimmungen unter dem
gemeinsamen API-Prefix `/api/v2/polls`. Der Diskriminator `type` unterscheidet
drei Arten, die im Client gebündelt angezeigt werden, sich intern aber stark
unterscheiden:

| `type` | Vorbild | Kern |
|--------|---------|------|
| `form` | Google/Microsoft Forms | Beliebig viele Fragen, Nutzer geben Antworten ein. |
| `appointment` | Doodle/Framadate | Terminvorschläge, Verfügbarkeit `yes`/`maybe`/`no`, optionale Gegenvorschläge, einmalige (änderbare) Abstimmung pro Nutzer/Option. |
| `vote` | anonymes Voting | Nur die Teilnahme wird erfasst (Doppelwahl verhindern), Stimmen werden gezählt; **keine** Verknüpfung Nutzer ↔ Antwort; einmalig, nicht änderbar. |

## Fixierte Entscheidungen

- **Zugriff** pro Umfrage: `invited` (nur Eingeladene + Ersteller) oder
  `all_users` (alle Angemeldeten). Es gibt keine Link-/ID-Freigabe.
- **Frist:** `closesAt` optional, plus `status` `open`/`closed`.
- **Formular-Modus:** `submissionMode` `single` (einmal, editierbar bis Frist)
  oder `multiple`.
- **Formular-Ergebnisse:** `resultsVisibility` `creator` (nur Ersteller/Admin)
  oder `participants`.
- **Terminfindung:** alle Teilnehmer sehen alle Stimmen (Doodle-artig);
  Ersteller legt finalen Termin fest; `allowCounterProposals` steuert
  Gegenvorschläge.
- **Anonyme Abstimmung:** Teilnehmerkennung als HMAC-Hash statt `userId`;
  einmalig, nicht änderbar; Ergebnis erst nach `closed` sichtbar.
  `allowMultiple` steuert, ob eine oder mehrere Optionen gewählt werden dürfen.
- **Longtext:** Plain Text mit Zeilenumbrüchen (kein HTML/Markdown-Rendering).
- **Realtime:** nur REST, kein Centrifugo.

## Datenbankmodell

Gemeinsamer Header + gemeinsame Kindtabellen + typspezifische Antwort-/
Stimmtabellen. Migration: `database/migrations/20260927000000_polls.sql`.

### `Poll` (Header)

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | varchar(191) | PK (UUIDv7) |
| `type` | enum(`form`,`appointment`,`vote`) | Umfrageart |
| `creatorId` | varchar(191) | FK → `User`, ON DELETE CASCADE |
| `title` | varchar(255) | Titel |
| `description` | text | Beschreibung (Plain Text) |
| `status` | enum(`open`,`closed`) | Default `open` |
| `closesAt` | datetime(3) | Optionale Frist (UTC) |
| `accessMode` | enum(`invited`,`all_users`) | Default `invited` |
| `submissionMode` | enum(`single`,`multiple`) | Nur `form` |
| `resultsVisibility` | enum(`creator`,`participants`) | Nur `form` |
| `allowCounterProposals` | tinyint | Nur `appointment` |
| `allowMultiple` | tinyint | Nur `vote`; Mehrfachauswahl erlaubt |
| `finalizedOptionId` | varchar(191) | Nur `appointment`; bewusst ohne FK (Kreisabhängigkeit), Integrität im Service |
| `reminderSentAt` | datetime(3) | Cron: Deadline-Reminder gesendet |
| `createdAt`/`updatedAt` | datetime(3) | Zeitstempel (UTC) |

### `PollQuestion` / `PollOption`

- `PollQuestion` hält Fragen (`type`, `title`, `description`, `isRequired`,
  `position`, `config` als JSON).
- `PollOption` dient sowohl als Antwortoption für Auswahl-Fragen
  (`questionId`) als auch als Terminvorschlag (`allDay`, `timezone`,
  `startAt`/`endAt` bzw. `startDate`/`endDate`) und als Vote-Option
  (`questionId` null). Gegenvorschläge sind `PollOption` mit
  `isCounterProposal=1` und `proposedBy`.

### `PollInvite`

Einladungen (`pollId`, `userId`, `isIndispensable`), eindeutig je
`(pollId,userId)`.

### `PollResponse` / `PollAnswer` (`form`)

- `PollResponse`: eine Antwort pro Nutzer (bzw. mehrere je Modus).
- `PollAnswer`: Wert je Frage (`responseId`, `questionId`, `value`).
  `value`-Semantik siehe [types.md](./types.md).

### `PollAvailabilityVote` (`appointment`)

Eine Zeile je `(pollId, optionId, userId)` mit `availability`
(`yes`/`maybe`/`no`); Upsert über vollständiges Ersetzen.

### `PollVote` (`vote`)

`pollId`, `optionId`, `participantHash char(64)` — **kein** `userId`.
`participantHash = HMAC-SHA256("pollId:userId", POLL_ANONYMITY_SECRET)`.
Eindeutig je `(pollId, participantHash, optionId)`; Einmaligkeit zusätzlich
vor dem Insert über `(pollId, participantHash)` geprüft (`already_voted`).

## Fragetypen & Validierung

Die zentrale, DB-freie Klasse `src/Services/Poll/PollAnswerValidator.php`
validiert und normalisiert Antworten. Details je Typ, `config`-Felder und
Speicher-Semantik: siehe [types.md](./types.md).

## Endpunkte

Alle Routen sind mit `AuthenticationMiddleware` geschützt. Antworten folgen dem
bestehenden Stil (`ResponseFactory::json/paginated/noContent`), Fehlercodes über
Controller-`ERROR_MAP`.

| Methode | Pfad | Zweck | Berechtigung |
|---------|------|-------|--------------|
| POST | `/polls` | Umfrage anlegen (atomar mit Fragen/Optionen/Einladungen) | angemeldet |
| GET | `/polls` | Sichtbare Umfragen (`type`,`status`, paginiert) | angemeldet |
| GET | `/polls/{id}` | Detail inkl. Fragen/Optionen + eigener Status | sichtbar |
| PATCH | `/polls/{id}` | Meta/Settings ändern | Ersteller/Admin |
| DELETE | `/polls/{id}` | Löschen | Ersteller/Admin |
| POST | `/polls/{id}/close` | Manuell schließen | Ersteller/Admin |
| GET | `/polls/{id}/invites` | Einladungen listen | Ersteller/Admin |
| POST | `/polls/{id}/invites` | Nutzer einladen | Ersteller/Admin |
| DELETE | `/polls/{id}/invites/{userId}` | Einladung entfernen | Ersteller/Admin |
| GET | `/polls/{id}/responses` | Formular-Antworten (Roh) | Ersteller/Admin oder Teilnehmer bei `participants` |
| POST | `/polls/{id}/responses` | Formular absenden | sichtbar + offen + Modus |
| GET | `/polls/{id}/responses/me` | Eigene Antwort | Eigentümer |
| PATCH | `/polls/{id}/responses/{responseId}` | Antwort ändern | Eigentümer, `single`, offen |
| POST | `/polls/{id}/options` | Gegenvorschlag (Terminfindung) | sichtbar + `allowCounterProposals` |
| DELETE | `/polls/{id}/options/{optionId}` | Terminoption löschen (jede Option für Ersteller/Admin, sonst eigener Gegenvorschlag) | Vorschlagender/Ersteller/Admin |
| GET | `/polls/{id}/availability` | Alle Verfügbarkeiten | sichtbar |
| PUT | `/polls/{id}/availability` | Eigene Verfügbarkeiten setzen (Upsert) | sichtbar + offen |
| POST | `/polls/{id}/finalize` | Termin festlegen | Ersteller/Admin |
| GET | `/polls/{id}/vote-status` | Ob Nutzer bereits abgestimmt hat | sichtbar |
| POST | `/polls/{id}/vote` | Einmalig abstimmen (`optionIds[]`) | sichtbar + offen |
| GET | `/polls/{id}/results` | Ergebnis-Zählungen | erst nach `closed`; Ersteller/Admin |

### Fehlercodes (Auszug)

`not_found`, `forbidden`, `title_required`, `invalid_type`, `invalid_config`,
`invalid_answer`, `answer_required`, `invalid_status`, `invalid_access_mode`,
`invalid_submission_mode`, `invalid_visibility`, `invalid_option`,
`invalid_target`, `option_not_found`, `time_forbidden`, `date_forbidden`,
`poll_closed`, `already_responded`, `already_voted`, `results_hidden`,
`counter_proposals_disabled`.

## Terminfindung (`appointment`)

- Optionen enthalten Zeitfelder nach der AGENTS Date/Time Convention:
  getaktet `startAt`/`endAt` (RFC 3339 mit Offset) + `timezone`, ganztägig
  `startDate`/`endDate` + `timezone`; die jeweils andere Feldgruppe ist
  verboten (`time_forbidden`/`date_forbidden`).
- `PUT /availability` ersetzt die eigenen Verfügbarkeiten vollständig.
- Gegenvorschläge (`POST /options`) nur bei `allowCounterProposals`.
- `POST /finalize` setzt `finalizedOptionId`, schließt die Umfrage und
  benachrichtigt Teilnehmer.

## Anonyme Abstimmung (`vote`)

- `POST /vote` akzeptiert `optionIds[]` (mindestens eine gültige Option der
  Umfrage). Bei `allowMultiple=false` ist genau eine Option erlaubt, sonst
  `invalid_answer`.
- Doppelwahl wird über den `participantHash` verhindert (`already_voted`).
- Nachträgliche Änderung ist nicht möglich.
- `GET /results` ist erst nach `closed` und nur für Ersteller/Admin sichtbar.
- Der Client sollte seine HMAC-Kennung nicht preisgeben; die API gibt sie nie
  zurück.

## Notifications

Vier Typen (Details: [../notifications/types.md](../notifications/types.md)):

| Typ | Trigger | Empfänger |
|-----|---------|-----------|
| `poll_invite` | Einladung | Eingeladene:r |
| `poll_counter_proposal` | Gegenvorschlag | Ersteller + übrige Teilnehmer |
| `poll_finalized` | festgelegt / geschlossen | alle Teilnehmer |
| `poll_deadline_reminder` | Cron vor Frist | Teilnehmer ohne Antwort/Stimme |

Alle vier unterstützen die Präferenz `custom` mit `pollIds` (Denylist).
Bei `vote`-Umfragen prüft der Reminder die Teilnahme über den HMAC-Hash, ohne
Anonymität zu verletzen.

## Cron

`polls_deadline` (stündlich, siehe [../CRON.md](../CRON.md)): schließt
abgelaufene Umfragen und erinnert Teilnehmer innerhalb von 24 h vor `closesAt`.

## Konfiguration

`.env`:

```
POLL_ANONYMITY_SECRET=<zufälliges, stabiles Secret>
```

Das Secret darf sich nicht ändern, solange anonyme Abstimmungen laufen, da sich
sonst der `participantHash` und damit die Doppelwahl-Erkennung ändert.

## Tests

- DB-frei (lokal): `PollAnswerValidatorTest`, `PollAnonymityTest`,
  `PollPolicyTest`, `PollNotificationDataTest`.
- Integration (Server, DB): Formular- (single/multiple, Sichtbarkeit),
  Terminfindungs- (Upsert, Gegenvorschlag, Löschen, Finalisierung) und
  Abstimmungs-Flows (einmalig, Anonymität, Einfach-/Mehrfachauswahl,
  Ergebnisse erst nach `closed`) sowie Zugriffsmodi (`invited` vs.
  `all_users`).
