# Agent Instructions for Sinclear Beyond API

## OpenAPI Documentation
It is elementarily important for the collaboration of SinclearAPI with all clients that the `openapi.yaml` is always up to date.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Verify the accuracy and completeness of the `openapi.yaml` file.
2. Ensure that all moment-by-moment existing API endpoints and functions are fully and correctly reflected in the specification.

## Documentation
The `docs/` directory contains developer-facing documentation for the API.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Update the relevant documentation files in `docs/` to reflect the changes.
2. Ensure that all flows, endpoints, and configuration are accurately documented.

## Notification-Typen-Übersicht
Die Datei `docs/notifications/types.md` listet tabellarisch ausschließlich die Notification-Typen, ihre Trigger und die übermittelten Eigenschaften (Relations, API-generierte Titel/Texte).

**Requirement:**
Bei jeder Änderung an Notification-Typen (Trigger, Empfänger, Relations oder generierte Titel/Texte) MUSS `docs/notifications/types.md` aktualisiert werden. Diese Datei ist die maßgebliche Übersicht für alle Notification-Typen.

Zusätzlich MUSS bei jedem neuen/entfernten Notification-Typ geprüft werden, ob er einen eigenen oder einen bereits vorhandenen vereinheitlichten Eintrag in `NotificationPreferenceService::KNOWN_TYPES` benötigt. `KNOWN_TYPES` entspricht den vom Preferences-Endpoint angebotenen Schlüsseln, nicht zwingend den internen Schlüsseln von `NotificationService::CONTENT_TEMPLATES`; die Zuordnung erfolgt über das Type-Mapping. Typen, die die Präferenz `custom` unterstützen, werden in `NotificationPreferenceService::CUSTOMIZABLE_TYPES` gepflegt.

## Notification-Präferenzen
Nutzer können jeden Notification-Typ aktivieren (`enabled`), deaktivieren (`disabled`) oder – sofern unterstützt – per Denylist filtern (`custom`). Gespeichert in der Tabelle `NotificationPreference`; kein Eintrag bedeutet Standard `enabled`.

**Requirement:**
Bei jeder Änderung am Präferenz-System (Zustände, `custom`-Typen, `customData`-Format) MÜSSEN `docs/notifications/readme.md` und die zugehörigen Schemas/Endpunkte in `openapi.yaml` aktualisiert werden. Der Versand-Filter liegt zentral in `NotificationService::create()`.

**custom = Denylist (verbindliche Semantik):** Die IDs im `customData` werden vom Versand ausgeschlossen; ohne Einträge wird alles zugestellt. Das Format ist pro Typ fest definiert (`forumIds` für Forum-Typen, `userIds` für `story_post`) und darf NICHT in Allowlist-Logik umgedeutet werden.

## MCP-Server
Der MCP-Server (`sinclear-docs-mcp`) stellt die gesamte Dokumentation aus `docs/` über das Tool `get_documentation` bereit. Alle `.md`-Dateien werden automatisch als Topics gescannt und als `enum`-Werte des Tools angeboten (auch verschachtelte Pfade wie `notifications/list`).

**Requirement:**
Die Datei `MCP.md` enthält die vollständigen Regeln für MCP-Server-Pflege und Wartung. Bei jeder Änderung an der Dokumentation oder am MCP-Server MUSS `MCP.md` konsultiert und bei Bedarf aktualisiert werden. Das Tool-Schema darfs NICHT auf Kern-Themen beschränkt werden – MCP-Clients validieren das `enum` streng und blockieren sonst gültige Topics.

## Cron-Jobs / Geplante Aufgaben
Das Projekt verwendet eine zentrale `bin/cron.php` als Taktgeber. Tasks werden in `src/Services/Cron/Tasks/` als Klassen implementiert und in `bin/cron.php` registriert.

Die Datei `docs/CRON.md` enthält eine vollständige Übersicht aller Cron-Tasks und ihrer Intervalle.

**Requirement:**
Wenn ein bestehender Cron-Task verändert, ein neuer hinzugefügt oder einer gelöscht wird, MUSS die Datei `docs/CRON.md` aktualisiert werden:
1. Tabelle der Übersicht aktualisieren (Task-Name, Intervall, Beschreibung).
2. Details des betroffenen Cron-Tasks anpassen oder neuen Eintrag ergänzen.
3. Sicherstellen, dass der Task in `bin/cron.php` registriert ist.

## Security and File Access
To ensure that secrets inside the .env file or log files cannot be read by anyone, a `.htaccess` is present to secure the API.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Verify the accuracy and completeness of the `.htaccess` file for the security of the project.
2. Ensure that all files in the project folder have the correct access rights or denials set in the `.htaccess` file to protect the secrets of the API and it's code and config.

## Coding Standards
- Use PHP 8.4 features where appropriate.
- Follow the established CRUD pattern using `ResourceRegistry.php` for standard resources.
- Ensure all endpoints are secured with the appropriate Policy classes.

## Admin Dashboard Consistency
Das Admin Dashboard (`templates/admin/`) muss immer den aktuellen Zustand der API und des Clients widerspiegeln.

**Requirement:**
Nach jeder Änderung an der API (Routes, Controllers, DTOs, Services) oder am Client (Router, Seiten), MUSS geprüft werden:
1. Alle im Admin Dashboard angebotenen Bearbeitungsmöglichkeiten (Formulare, Dropdowns, Listen) sind aktuell und funktionieren.
2. Deep-Links, Auswahllisten und Referenzwerte im Dashboard stimmen mit den tatsächlichen Werten im Client (z.B. GoRouter-Routes) überein.
3. Keine veralteten oder nicht mehr existierenden Seiten/Optionen werden im Dashboard angeboten.
4. Neue API-Endpunkte oder Client-Seiten, die eine Admin-Bearbeitung erfordern, sind im Dashboard vorhanden.

## Testing and Deployment
Auf dem lokalen Entwicklungs-System wird NICHT gegen eine Datenbank getestet (kein PDO/MySQL verfügbar). Tests (phpunit, phpstan) werden automatisch beim Deploy über das Update-Skript auf dem Server ausgeführt. Der Betreiber berichtet Fehler zurück.

**Requirement:**
- Führe `update.sh` NIE ungefragt aus.
- Verbinde dich NIE ungefragt per SSH mit dem Server.
- Lokal sind nur statische Prüfungen erlaubt: `php -l`, `vendor/bin/phpstan`, Unit-Tests ohne DB-Abhängigkeit.
- DB-abhängige Integrationstests dürfen geschrieben werden, laufen aber erst auf dem Server.

## Date/Time Convention (timezone-aware)
The API exchanges timestamps with explicit timezone information. Every
timestamped entity (CalendarEvent, TravelEvent, TravelTrip) carries its own
IANA timezone. There is no UTC-only requirement anymore.

### Format
- **Input (von Clients):**
  - **Getaktet (timed):** `startAt`/`endAt` als RFC 3339 **mit Offset**
    (`2026-09-26T14:30:00+02:00` oder `...Z`) plus `timezone` (IANA, z. B.
    `Europe/Berlin`).
  - **Ganztägig (allDay: true):** `startDate`/`endDate` als zivile Tage
    (`YYYY-MM-DD`, inklusives Ende) plus `timezone`; keine Uhrzeitfelder.
  - Die jeweils andere Feldgruppe darf nicht mitgesendet werden
    (`date_forbidden` bei getaktet, `time_forbidden` bei ganztägig).
- **Output (an Clients):** Identisches Format wie Input. Getaktete Einträge
  werden als RFC 3339 im Offset der Eintragszeitzone ausgegeben, ganztägige
  als `startDate`/`endDate`. `createdAt`/`updatedAt` sind RFC 3339 in UTC (`Z`).
- **Zeitraum-Parameter** (`start`/`end` bei `GET /calendar`, `GET /calendar/all`)
  sind zivile Tage; die optionale Query-Zeitzone (`timezone`, Default `UTC`)
  legt fest, in welcher Zone die Tagesgrenzen liegen.

### Verantwortlichkeiten
- **API:** Validiert IANA-Zeitzonen, speichert getaktete Einträge als
  UTC-Instants (`DATETIME(3)`) plus `timezone`, ganztägige als zivile Tage
  plus `timezone`. Sortierung und Zeitraumfilter laufen auf UTC-Instants.
- **Clients:** Senden Wandzeiten mit korrektem Offset (bzw. Datum) und der
  gemeinten IANA-Zeitzone; rechnen bei der Anzeige in die Eintragszeitzone um.
  Die eigene Zeitzone kommt aus `UserPreferences.timezone` bzw. der
  Gerätezeitzone.

### Begründung
- Absolute Zeitpunkte bleiben eindeutig (UTC-Instant), während die gemeinte
  Wandzeit über die IANA-Zeitzone erhalten bleibt (Anzeige, Bearbeitung,
  Sommer-/Winterzeit).
- Ganztägige Einträge sind zivile Tage und werden nicht verschoben.
- Entspricht dem Branchenstandard (Google Calendar, RFC 5545/CalDAV,
  RFC 3339).

