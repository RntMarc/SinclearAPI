# Implementation Plan – Reiseplanung (Trip Planning)

> **Status:** Phase 1 (Datenbank & Migration) implementiert (2026-09-29);
> Phase 2 (API & Rechte) implementiert (2026-09-29).
> **Bereich:** `travel` – mehrstufige Reiseplanung
> **API-Prefix:** `/trips/planning` (Phase 2)

## WICHTIGE REGELN FÜR DEN AUSFÜHRENDEN AGENTEN

> **ACHTUNG AGENT:**
> 1. Führe alle Schritte **einzeln und nacheinander** in der angegebenen Reihenfolge aus.
> 2. Teste und verifiziere das Ergebnis nach **jedem einzelnen Schritt**.
> 3. Hake die Checkbox `[ ]` -> `[x]` **sofort nach erfolgreichem Abschluss und Verifikation** jedes Schrittes ab.
> 4. Befolge durchgehend `AGENTS.md`: `openapi.yaml` aktuell halten, `docs/` aktualisieren, Policies für alle Endpunkte, `.htaccess` prüfen, lokale Prüfungen (`php -l`, `vendor/bin/phpstan`, DB-freie Unit-Tests) voranstellen. `update.sh` NIE ungefragt ausführen, keine SSH-Verbindung.
> 5. Bei jeder Änderung an Notification-Typen: `docs/notifications/types.md` **und** `docs/notifications/readme.md` + `NotificationPreferenceService` aktualisieren.
> 6. Der übergeordnete `TripPlanningMasterplan.md` wird **nicht** bearbeitet.

---

## Ziel & Beschreibung

Eine Reise kann als **Planungsreise** angelegt werden und durchläuft drei feste
Kernphasen:

1. **Wann und wer?** – Terminfindung und Teilnehmerklärung inkl. Chat.
2. **Wo und wie?** – An-/Abreise, Mitfahrgelegenheiten, Unterkunft, Budget.
3. **Was machen wir?** – Tagesprogramm und Events.

Die Planung ist jedem aktiven Planungsmitglied zugänglich; die Leitung trifft am
Ende die verbindliche Festlegung und aktiviert die Reise. Dieselbe Reise wird
nach Abschluss der Planung aktiviert, sodass ID, Planungshistorie und Chat
erhalten bleiben.

Der vorliegende Plan beschreibt **Phase 1: Datenbank und Migration**. Die
Phasen 2–7 des Masterplans (API/Rechte, Client, Aktivierung, Tests) sind hier
nur als Ausblick gelistet und **nicht** Teil dieses Schritts.

---

## 1. Fixierte Entscheidungen (Phase 1)

| Thema | Entscheidung |
|---|---|
| `TravelTrip.state` | `enum('planning','active','cancelled')`, Default `'active'` |
| `TravelPlanMember.status` | `enum('invited','accepted','declined','inactive')`, Default `'invited'` |
| Unterkunftspreis operativ | Neue Spalten `pricePerPersonPerNight` + `currency` an `TravelAccommodationTrip` (NULL-Default, Befüllung bei Aktivierung) |
| Namenskonvention | Prefix `TravelPlan*`, Spalten lowercase camelCase (wie `Poll`/`TravelTrip`) |
| Phasenthemen (Topic-Keys) | `participants`, `travel`, `program` (serverseitig fest) |
| Migration | Eine Datei, additiv und rerun-sicher, kein Datenverlust |
| `cancelled` | Vorgesehen, in Phase 1 ungenutzt (keine Storno-/Archivlogik vorhanden) |

---

## 2. Datenbankschema (umgesetzt)

Datei: `database/migrations/20260930000000_travel_planning.sql`

Struktur: drei bewachende `ALTER TABLE` (über `information_schema` +
`PREPARE/EXECUTE`, wie in `20260928000000_travel_leader_roles.sql`) und acht
`CREATE TABLE IF NOT EXISTS`. Kollation `utf8mb4_unicode_ci`, `datetime(3)`,
`ENGINE=InnoDB`.

### 2.1 Erweiterungen bestehender Tabellen

- `TravelTrip.state` (`enum('planning','active','cancelled')`, Default `active`)
  → Bestandsreisen bleiben operativ (`active`).
- `TravelAccommodationTrip.pricePerPersonPerNight` (`decimal(10,2) NULL`)
- `TravelAccommodationTrip.currency` (`char(3) NULL`)
  → reise-spezifischer Preis, bei Aktivierung aus der gewählten
  `TravelPlanAccommodationOption` übernommen.

### 2.2 Neue Tabellen

| Tabelle | Zweck | Wesentliche Felder |
|---|---|---|
| `TravelPlanMember` | Planungsteilnehmer | `tripId`, `userId` (UNIQUE), `status`, `origin`, `deactivatedAt` |
| `TravelPlanTopic` | Phasenstatus | `tripId`, `topic` (UNIQUE je Reise), `status` |
| `TravelPlanDateOption` | Terminoptionen | `tripId`, Zeitfelder (`allDay`/`timezone`/`startAt`/`endAt`/`startDate`/`endDate`), `isFinal`, `proposedBy` |
| `TravelPlanDateResponse` | Verfügbarkeit je Option/Mitglied | `dateOptionId`, `userId` (UNIQUE), `availability` |
| `TravelPlanTransport` | Transportpräferenz | `tripId`, `userId`, `direction` (UNIQUE je Richtung), `mode`, `offersRide`, `availableSeats` |
| `TravelPlanAccommodationOption` | Unterkunftsoption + Preis | `tripId`, `accommodationId`, OSM-/Adressdaten, `pricePerPersonPerNight`, `currency`, `isSelected` |
| `TravelPlanEvent` | Tagesprogramm-Vorschlag | `tripId`, Zeit-/Ortsdaten, `isConfirmed`, `confirmedEventId`, `proposedBy` |
| `TravelPlanEventInterest` | Interesse je Vorschlag/Mitglied | `eventSuggestionId`, `userId` (UNIQUE), `interest` |

Alle Fremdschlüssel referenzieren `User(id)`, `TravelTrip(id)` bzw.
`TravelAccommodation(ID)`; `ON DELETE CASCADE` für Kinddaten, `SET NULL` für
optionale Urheber-/Katalogverweise. Alle referenzierenden Spalten sind
indiziert. `confirmedEventId` bewusst ohne FK (Kreisabhängigkeit
Planung ↔ operatives Event; Integrität im Service).

---

## 3. Reihenfolge zur Implementierung

### Phase 1: Datenbank-Migration (umgesetzt)

- [x] 1.1 **Migrationsskript erstellen** (`database/migrations/20260930000000_travel_planning.sql`): drei bewachende `ALTER TABLE` (`TravelTrip.state`, `TravelAccommodationTrip.pricePerPersonPerNight`, `.currency`) + acht `CREATE TABLE IF NOT EXISTS` laut Abschnitt 2.
  - Verifikation: 8 `CREATE TABLE`-Statements und 3 `ALTER`-Blöcke vorhanden; Datei ist reines ASCII (`LC_ALL=C grep -P "[^\x00-\x7F]"` findet nichts).
- [x] 1.2 **Skript reviewen**: FKs konsistent, Kollation `utf8mb4_unicode_ci`, `datetime(3)`, Indizes/Uniques vorhanden, Idempotenz über Existenzprüfungen.
  - Verifikation: FK-Eltern existieren (`User`, `TravelTrip`, `TravelAccommodation`); jede FK-Spalte besitzt einen Index; getrennte Existenzprüfung je neuer Spalte; kein `DROP`/`DELETE` (additiv).
- [x] 1.3 **`docs/travel/readme.md` aktualisieren**: Tabellenliste um die acht `TravelPlan*`-Tabellen ergänzt, `state`/Preis-Spalten vermerkt, Abschnitt „Planungsreisen (Vorbereitung)" hinzugefügt.
  - Verifikation: Tabellen und Statuswerte stimmen mit der Migration überein.
- [x] 1.4 **Diese Plandatei anlegen** (`docs/travel/planning-plan.md`).
  - Verifikation: Datei vorhanden und konsistent zum Schema.

### Phase 1 – Verifikation & Übergabe

- [x] 1.5 **Lokale Prüfung**: Keine PHP-Dateien geändert → kein `php -l`/`phpstan` erforderlich. DB-Tests laufen laut `AGENTS.md` erst auf dem Server; lokal nur statische/prüfende Schritte.
- [x] 1.6 **`.htaccess`/`openapi.yaml`/`docs/CRON.md`/Notifications**: In Phase 1 unverändert, da keine Routen, Endpunkte, Cron-Tasks oder Notification-Typen hinzukommen. Prüfvermerk siehe Abschluss.
- [ ] 1.7 **Betreiber-Folgeaufgaben** (nicht lokal ausführbar): Migration am Server anwenden; Schema-Snapshot (`database/status_*`) neu generieren. `update.sh` NICHT ungefragt ausführen.

---

## 4. Rückbau-/Rolloutpfad

- Rein additive Migration (`ADD COLUMN`, `CREATE TABLE IF NOT EXISTS`) → ohne
  Bestandsdatenverlust rückbaubar:
  `DROP TABLE TravelPlanEventInterest, TravelPlanEvent, TravelPlanAccommodationOption, TravelPlanTransport, TravelPlanDateResponse, TravelPlanDateOption, TravelPlanTopic, TravelPlanMember;`
  sowie `ALTER TABLE TravelTrip DROP COLUMN state;` und
  `ALTER TABLE TravelAccommodationTrip DROP COLUMN pricePerPersonPerNight, DROP COLUMN currency;`.
- Bestandsreisen: `state='active'` per Default, keine Verhaltensänderung.

---

## 5. Ausblick: Folgephasen (nicht Teil dieses Schritts)

- **Phase 2 – API & Rechte:** Planungsservice + Policy, Endpunkte, Trennung
  normaler Reise-PATCHes von autorisierten Planungskommandos; `state` nur über
  dedizierte Planungsaktionen änderbar; OpenAPI/`docs/`/`.htaccess`/Admin prüfen.
- **Phase 3 – Chat & Benachrichtigungen:** Chat-Mitgliedschaft aus aktiven
  Planungsmitgliedern; neue Notification-Typen inkl. `docs/notifications/types.md`
  und `NotificationPreferenceService`.
- **Phase 4 – Aktivierung:** transaktional & idempotent: `TravelPlanMember` →
  `TravelRelation`, gewählte Unterkunft → `TravelAccommodationTrip` (inkl. Preis),
  bestätigte `TravelPlanEvent` → `TravelEvent` (`confirmedEventId`), Chat-Abgleich.
- **Phase 5–7 – Client, Tests, Abnahme:** Flutter-Modelle/Travel-Service,
  Planungsoberfläche im Design-System, API- und Flutter-Tests.

---

## Implementierungs-Abschluss

> **Status:** Phase 1 abgeschlossen (Datenbank & Migration).
> **Datum:** 2026-09-29

### Abweichungen / Hinweise

- **Schema-Snapshot:** `database/status_*/` wird vom Betreiber am Server
  erzeugt und ist lokal nicht aktualisierbar (kein lokaler DB-Zugriff). Als
  Betreiber-Folgeaufgabe vermerkt.
- **`cancelled`:** Aufgenommen, aber in Phase 1 ohne Verhalten; dient der
  späteren Storno-/Archivlogik.
- **`confirmedEventId`:** bewusst ohne FK (zirkuläre Abhängigkeit zum erzeugten
  `TravelEvent`); Idempotenz und Integrität werden im Service (Phase 4)
  sichergestellt.
- **Phasenthemen:** Anlage der drei `TravelPlanTopic`-Einträge (inkl.
  Überspringen beim Erstellen) erfolgt im Service/Controller (Phase 2), nicht per
  DB-Trigger.

---

## Phase 2 – API und Rechte (umgesetzt)

> **Status:** Phase 2 abgeschlossen (API, Rechte, Chat, Benachrichtigungen,
> Aktivierung). **Datum:** 2026-09-29

### Fixierte Entscheidungen (Phase 2)

| Thema | Entscheidung |
|---|---|
| Leitung | Neue additive Spalte `TravelPlanMember.role` (`leader`/`member`); Leitung bewusst nicht über `TravelRelation` |
| API-Prefix | `/trips/planning` (nested, registriert vor `/trips/{id}`) |
| Aktives Planungsmitglied | `status != 'inactive'` (`invited`/`accepted`/`declined` behalten Zugriff) |
| `state`-Änderung | Nur `POST /trips/planning` und `POST /trips/planning/{id}/activate`; nie per `PATCH /trips/{id}` |
| Aktivierung übernimmt | Leader(s) → `TravelRelation.leader`; `accepted` → `participant`; `invited`/`declined`/`inactive` nicht |
| Benachrichtigungen | `trip_planning_invite`, `trip_planning_response`, `trip_planning_finalized`, `trip_planning_activated` (kein `custom`) |

### Neue/geänderte Dateien

- **Migration:** `database/migrations/20261001000000_travel_planning_leader.sql`
  (`TravelPlanMember.role`, additiv/rerun-sicher).
- **Policy:** `src/Security/Policy/TravelPlanningPolicy.php`
- **Service:** `src/Services/TravelPlanningService.php`,
  `src/Services/TravelPlanningNotificationService.php`
- **Controller:** `src/Controllers/TravelPlanningController.php`
- **Repositories:** `TravelPlanMemberRepository`, `TravelPlanTopicRepository`,
  `TravelPlanDateOptionRepository`, `TravelPlanDateResponseRepository`,
  `TravelPlanTransportRepository`, `TravelPlanAccommodationOptionRepository`,
  `TravelPlanEventRepository`, `TravelPlanEventInterestRepository`
- **Erweitert:** `TravelTripRepository` (`setState`, `state` in `create`,
  `findPlanningByParticipant`), `TravelAccommodationRepository`
  (`linkToTripWithPrice`), `TravelChatService` (`createForPlanningTrip`,
  `syncPlanningMembers`), `NotificationService`,
  `NotificationPreferenceService`, `config/routes.php`,
  `config/dependencies.php`, `openapi.yaml`, `docs/travel/readme.md`,
  `docs/notifications/{types,readme}.md`
- **Tests:** `tests/Unit/TravelPlanningPolicyTest.php`,
  `tests/Unit/TravelPlanningErrorTest.php`,
  `tests/Unit/TravelPlanNotificationDataTest.php`

### Aktivierung (Algorithmus)

`POST /trips/planning/{id}/activate` (Leitung), transaktional über die
gemeinsame `PDO`-Instanz, idempotent (`state=active` → No-op):

1. finale Terminoption (`isFinal`) → `TravelTrip`-Zeitfelder;
2. `TravelTrip.state = 'active'`;
3. Mitglieder → `TravelRelation` (Leader als `leader`, `accepted` als
   `participant`; idempotent via `isParticipant`);
4. gewählte Unterkunft → `TravelAccommodationTrip` inkl.
   `pricePerPersonPerNight`/`currency` (Katalogeintrag bei Bedarf angelegt);
5. bestätigte `TravelPlanEvent` → `TravelEvent` + `confirmedEventId`;
6. Chat-Abgleich aus `TravelRelation`; danach Benachrichtigung.

### Verifikation

- `php -l` auf allen neuen/geänderten PHP-Dateien: fehlerfrei.
- `vendor/bin/phpstan --level=5` auf allen neuen/geänderten Dateien:
  fehlerfrei. Vorbestehende Hinweise in `NotificationService` /
  `NotificationPreferenceService` sowie `closure.unusedUse` in `config/routes.php`
  (nicht angefasste Zeilen) bleiben unverändert.
- `vendor/bin/phpunit --testsuite Unit`: die neuen Tests (30 Tests, 55
  Assertions) sind grün. Die übrigen Fehler der Unit-Suite sind ausschließlich
  `Class "PDO" not found` in vorbestehenden, DB-abhängigen Tests
  (`NotificationServiceTest`, `NotificationPreferenceServiceTest`,
  `AuthControllerTest`, `CalendarFeedServiceTest`) – lokal nicht ausführbar
  (kein PDO/MySQL), laufen erst auf dem Server.
- `openapi.yaml`: YAML valide, alle `$ref` (außer dem vorbestehenden
  `ReviewResponse/properties/data`) auflösbar, keine neuen doppelten Keys.
- `.htaccess`: keine Änderung erforderlich (alle Routen über `/api/v2`,
  keine neuen statischen Pfade/Geheimnisse).
- Admin-Dashboard: keine neue Bearbeitungsanforderung (Planung ist
  nutzergetrieben); Konsistenz geprüft, keine Formulare ergänzt.

### Abweichungen / Hinweise (Phase 2)

- **`TravelPlanMember.role`:** In Phase 1 nicht enthalten; als additive
  Migration in Phase 2 ergänzt, da die Leitung autorisiert werden muss.
- **Letzter-Leader-Invariante:** im Service über
  `TravelPlanningPolicy::canRemoveLeader` durchgesetzt (kein DB-Trigger).
- **Budget:** Phase 1 hat kein dediziertes Budget-Feld im Schema fixiert.
  Budgetangaben werden daher über den Unterkunftspreis
  (`TravelPlanAccommodationOption.pricePerPersonPerNight`/`currency`, für alle
  aktiven Mitglieder sichtbar) und das freie `TravelPlanTransport.notes`-Feld
  abgebildet; ein eigenes Budget-Feld wird bewusst nicht nachgezogen.
- **Chat im Transaktionsscope:** der Chat-Abgleich läuft innerhalb der
  Aktivierungstransaktion; die Centrifugo-Unsubscribe-Nebenwirkung ist
  bewusst in Kauf genommen.
- **Betreiber-Folgeaufgaben:** Migration
  `20261001000000_travel_planning_leader.sql` am Server anwenden;
  Schema-Snapshot (`database/status_*`) neu generieren. `update.sh` NICHT
  ungefragt ausführen.

