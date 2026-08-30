# Implementation Plan - Nutzungs-Ping (Usage Ping)

## WICHTIGE REGELN FÜR DEN AUSFÜHRENDEN AGENTEN

> **ACHTUNG AGENT:**
> 1. Führe alle Schritte **einzeln und nacheinander** aus.
> 2. Teste und verifiziere das Ergebnis nach **jedem einzelnen Schritt**.
> 3. Dokumentiere deine Änderungen und Testergebnisse kurz nach jedem Schritt.
> 4. Hake die Checkbox `[ ]` -> `[x]` in diesem Dokument (`docs/ping/plan.md`) **sofort nach dem erfolgreichen Abschluss und der Verifizierung jedes Schrittes** ab, bevor du zum nächsten Schritt übergehst.

---

## Ziel & Beschreibung

Beim Starten der mobilen / Web-App soll ein Ping an die API gesendet werden (`POST /user/ping`).
Dieser Ping erfasst in der Datenbank im Profil des Nutzers (`User`-Tabelle) den aktuellen Zeitpunkt der letzten Nutzung (`lastUsed`).
Administratoren erhalten dadurch Transparenz darüber, ob alle aktiven Nutzer über wichtige Ereignisse informiert wurden.

---

## Phasen & Arbeitsschritte

### Phase 1: Datenbank-Migration

- [ ] 1.1 **Migrationsskript erstellen**
  - Erstelle ein SQL-Migrationsskript `database/migrations/add_last_used_to_user.sql`.
  - Füge der Tabelle `User` die Spalte `lastUsed` vom Typ `DATETIME NULL DEFAULT NULL` hinzu.
  - Teste die Migration lokal mit dem Migrations-Runner (`php bin/migrate.php` bzw. direkt via PDO / DB).

---

### Phase 2: Datenbankschicht (Repository & Schema)

- [ ] 2.1 **`UserRepository` anpassen**
  - Erweitere `src/Repository/UserRepository.php`:
    - Füge die Spalte `lastUsed` in bestehende `SELECT`-Statements (`findById`, `findByEmail`, `findAll` etc.) ein.
    - Implementiere die Methode `updateLastUsed(string $userId): void`, die `UPDATE User SET lastUsed = NOW(3) WHERE id = ?` ausführt.
  - Verifiziere die Methode mittels Unit- / Integrationstest.

---

### Phase 3: Service- & Business-Logik

- [ ] 3.1 **`UserService` & `ProfileService` aktualisieren**
  - Erweitere `src/Services/UserService.php` bzw. `ProfileService.php`:
    - Erstelle/ergänze `recordPing(string $userId): void`, die das Repository aufruft.
    - Stelle sicher, dass `lastUsed` in den Profildaten (`formatUserBase`, `GET /user/me`, `GET /user/{userId}`) korrekt zurückgegeben wird (ISO 8601 formatiert oder Datetime-String).

---

### Phase 4: API-Controller & Routing

- [ ] 4.1 **Ping-Controller/Endpunkt erstellen**
  - Füge in `src/Controllers/UserController.php` (oder `ProfileController.php`) den Endpunkt `ping(Request $request, Response $response): Response` hinzu.
  - Extrahiere die `userId` aus dem authentifizierten Nutzer (`AuthenticatedUser` Attribute).
  - Rufe den Service zur Aktualisierung von `lastUsed` auf und gib `200 OK` mit JSON-Status `{ "status": "ok", "lastUsed": "..." }` zurück.
- [ ] 4.2 **Route & Dependency Injection konfigurieren**
  - Registriere den Endpunkt `POST /user/ping` in `config/routes.php` mit Authentifizierungs-Middleware (`AuthenticationMiddleware`).
  - Stelle sicher, dass alle Services und Repositories in `config/dependencies.php` ordnungsgemäß verdrahtet sind.

---

### Phase 5: Dokumentation & OpenAPI Specification

- [ ] 5.1 **`openapi.yaml` aktualisieren**
  - Füge das Feld `lastUsed` (type: string, format: date-time, nullable: true) im `UserBase`-Schema hinzu.
  - Dokumentiere den neuen Endpunkt `POST /user/ping`:
    - Request: Authentifiziert mit Bearer Token.
    - Response: `200 OK` mit Zeitstempel.
- [ ] 5.2 **Benutzer-Dokumentation `docs/user/readme.md` aktualisieren**
  - Trage den Endpunkt `POST /user/ping` und das Feld `lastUsed` in der Modul-Dokumentation ein.

---

### Phase 6: Automatisiertes Testen & Qualitätskontrolle

- [ ] 6.1 **PHPUnit Tests schreiben**
  - Erstelle einen Integrationstest (z.B. `tests/Functional/PingTest.php` oder `tests/Unit/UserServiceTest.php`), der folgendes prüft:
    1. Unauthentifizierte Anfragen auf `POST /user/ping` werden mit `401 Unauthorized` abgewiesen.
    2. Authentifizierte Anfragen aktualisieren den `lastUsed`-Zeitstempel des Nutzers in der DB und liefern `200 OK`.
- [ ] 6.2 **Gesamte Testsuite ausführen**
  - Führe `./vendor/bin/phpunit` aus und verifiziere, dass alle Tests grün sind.
