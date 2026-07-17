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

## Date/Time Convention (UTC-only)
The API operates exclusively in UTC. This is a hard requirement that all implementations MUST follow:

### Format
- **Input (von Clients):** `YYYY-MM-DD HH:MM:SS` (24h-Format, keine Millisekunden, keine Zeitzonenindikatoren)
- **Output (an Clients):** `YYYY-MM-DD HH:MM:SS` (identisches Format, bestätigt UTC)
- **Keine** ISO 8601-Erweiterungen wie `T`, `Z`, `+00:00`, `.000Z` oder Millisekunden/Mikrosekunden

### Verantwortlichkeiten
- **API:** Speichert und liefert ausschließlich UTC-Zeitstempel im Format `YYYY-MM-DD HH:MM:SS`. Keine Zeitzonen-Konvertierung im API-Code.
- **Clients:** Sind verantwortlich für die Umrechnung von UTC in die lokale Zeitzone des Nutzers (Anzeige) und für die Umrechnung lokaler Zeit in UTC vor dem Senden an die API.

### Begründung
- Vermeidet Inkonsistenzen durch mehrfache Zeitzonen-Konvertierung
- Hält die API einfach und deterministisch
- Verschiebt die Zeitzonen-Logik dorthin, wo sie hingehört: auf das Client-Gerät des Nutzers

## Transitous / Public Transport API
Der Public-Transport-Service verwendet die Transitous-API (MOTIS 2.x, `api.transitous.org`).
Die alte `v6.db.transport.rest` ist dauerhaft ausgefallen und wird nicht mehr verwendet.

**Anforderungen:**
1. Jeder HTTP-Request an Transitous MUSS einen `User-Agent`-Header enthalten:
   `SinclearBeyond/1.0 (dev@sinclear.com)`
2. Der `User-Agent` identifiziert unsere App gegenüber Transitous und ist Voraussetzung für die Nutzung.
3. Der `time`-Parameter an `/api/v6/plan` MUSS mit `Z`-Suffix gesendet werden (ISO 8601 UTC).
4. Transitous liefert `scheduledDeparture`/`scheduledArrival` als Planzeiten; die tatsächlichen Zeiten stehen in `departure`/`arrival`. Die Verspätung wird via `realTime`-Flag und Differenzberechnung ermittelt.
5. Nach jeder Änderung an der Public-Transport-Integration MUSS `docs/public-transport/readme.md` aktualisiert werden.
