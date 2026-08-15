# Implementationsplan für Story-Funktion (`stories_for_flutter`)

> **WICHTIGE REGELN FÜR DEN AGENTEN / BEARBEITER:**
> 1. Alle Schritte müssen strikt einzeln nacheinander ausgeführt werden.
> 2. Nach JEDEM einzelnen Schritt muss die Implementation gründlich getestet und dokumentiert werden.
> 3. Sobald ein Schritt erfolgreich abgeschlossen und verifiziert wurde, muss die entsprechende Checkbox `[ ]` SOFORT auf `[x]` gesetzt werden, bevor mit dem nächsten Schritt begonnen wird.

---

## Übersicht & Orientierung an `stories_for_flutter: ^1.3.3`

Das Paket `stories_for_flutter` verlangt eine Gruppenstruktur:
- **`StoryItem`**:
  - `name` (String): Name des Nutzers / Story-Autors
  - `thumbnail` (ImageProvider): Profilbild / Avatar-URL des Nutzers
  - `stories` (List<Widget>): Die einzelnen Story-Pages/Slides (Bilder/Texte/Captions)
- **Sichtbarkeitsregel**: Stories sind nach Veröffentlichung genau 7 Tage (1 Woche) für alle Nutzer sichtbar.
- **Story-Ansichten**: Verfolgung, ob eine Story vom aktuellen Nutzer bereits gesehen wurde (`StoryView`).

---

## Schritt 1: Datenbank-Erweiterung (PHP & MySQL)

- [ ] **1.1 Migration für Tabelle `Story` erstellen**
  - Erstelle Migration `database/migrations/create_stories_table.sql`.
  - Feldspezifikation: `id` (VARCHAR/UUID PK), `userId` (FK auf `User.id`), `mediaUrl` (VARCHAR/TEXT), `caption` (TEXT/Nullable), `createdAt` (DATETIME(3)), `expiresAt` (DATETIME(3) - automatisch `createdAt + 7 Tage`).
  - Indizes: `idx_story_user_created` (`userId`, `createdAt`), `idx_story_expires` (`expiresAt`).

- [ ] **1.2 Migration für Tabelle `StoryView` erstellen**
  - Erstelle Tabelle `StoryView` für Gesehen-Status: `id` (PK), `storyId` (FK auf `Story.id`), `userId` (FK auf `User.id`), `viewedAt` (DATETIME(3)).
  - Indizes & Constraints: Unique Key `(storyId, userId)`.

- [ ] **1.3 Datenbank-Migration ausführen und Schemaprüfung durchführen**
  - Migration mittels `php bin/migrate.php` (oder SQL-Import) ausführen.
  - Prüfen, dass Tabellen und Fremdschlüssel korrekt angelegt wurden.

---

## Schritt 2: PHP API Integration & Dokumentation

- [ ] **2.1 Entity & Repository (`StoryRepository`) implementieren**
  - Erstelle `src/Repository/StoryRepository.php`.
  - Implementiere Methoden zum Erstellen einer Story, Abrufen aktiver Stories (nur Stories der letzten 7 Tage, gruppiert nach Benutzer) und Löschen eigener Stories.

- [ ] **2.2 Policy & Berechtigungen (`StoryPolicy`) implementieren**
  - Erstelle Berechtigungslogik in `src/Security/Policy/StoryPolicy.php` (Nur der Ersteller darf eigene Stories löschen, alle authentifizierten Nutzer dürfen Stories lesen/erstellen).

- [ ] **2.3 Controller (`StoryController`) implementieren**
  - Erstelle `src/Controllers/StoryController.php`.
  - Endpunkte:
    - `GET /stories` (Story-Feed: Gruppiert nach Nutzer inkl. User-Name, Avatar und Liste von Story-Items der letzten 7 Tage)
    - `POST /stories` (Neue Story erstellen)
    - `GET /stories/{id}` (Einzelne Story abrufen)
    - `DELETE /stories/{id}` (Eigene Story löschen)
    - `POST /stories/{id}/view` (Story als gesehen markieren)

- [ ] **2.4 Routen in `config/routes.php` registrieren**
  - Gruppe `/stories` mit `AuthenticationMiddleware` in `config/routes.php` eintragen.

- [ ] **2.5 Dependency Injection & Services registrieren**
  - Repository & Controller in `config/dependencies.php` hinterlegen falls erforderlich.

- [ ] **2.6 OpenAPI Dokumentation in `openapi.yaml` aktualisieren**
  - Vollständige OpenAPI 3.0 Spezifikation für alle Story-Endpunkte (`/stories`, `/stories/{id}`, `/stories/{id}/view`) mit Schema-Definitionen für Requests und Responses ergänzen.

- [ ] **2.7 PHPUnit-Tests für Story-API erstellen und ausführen**
  - Erstelle Integrationstests in `tests/Functional/StoryTest.php`.
  - Testfälle: Story erstellen, Story-Feed abrufen (nur <7 Tage alte Stories), Story löschen, Berechtigungen (Fremde Story löschen verboten).
  - Tests ausführen und das Passieren verifizieren.

---

## Schritt 3: Flutter-App Integration (`stories_for_flutter`)

- [ ] **3.1 Datenmodelle für Flutter erstellen**
  - Erstelle `StorySlideModel` und `UserStoryGroupModel` (Mapping von API-Responses).
  - Implementiere Konvertierungs-Helfer von API-Datenmodell auf `stories_for_flutter` Strukturen (`StoryItem`, `name`, `thumbnail`, `stories`).

- [ ] **3.2 API Service Erweiterung in Flutter**
  - Erstelle `StoryApiService` mit Methoden `fetchStories()`, `createStory(mediaUrl, caption)`, `markAsViewed(storyId)` und `deleteStory(storyId)`.

- [ ] **3.3 Story Feed UI-Widget mit `stories_for_flutter: ^1.3.3` einbauen**
  - Erstelle `StoryFeedWidget` unter Verwendung des `Stories`-Widgets aus `stories_for_flutter`.
  - Binde User-Profile-Avatare als `thumbnail` und Story-Pages (`Scaffold` / `Container` mit Bild & Text) in `storyItemList` ein.

- [ ] **3.4 Story Erstellungs-UI & Upload Flow erstellen**
  - Erstelle Screen/Modal `CreateStoryScreen` zur Auswahl/Eingabe von Medien/Text-Captions und Absenden an die API.

- [ ] **3.5 Flutter Unit- & Widget-Tests erstellen**
  - Schreibe Tests für Modell-Parsing, API Service Mocking und Rendering des `StoryFeedWidget`.
  - Flutter Tests ausführen und Korrektheit verifizieren.
