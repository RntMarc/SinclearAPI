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

## Admin Dashboard Consistency
Das Admin Dashboard (`templates/admin/`) muss immer den aktuellen Zustand der API und des Clients widerspiegeln.

**Requirement:**
Nach jeder Änderung an der API (Routes, Controllers, DTOs, Services) oder am Client (Router, Seiten), MUSS geprüft werden:
1. Alle im Admin Dashboard angebotenen Bearbeitungsmöglichkeiten (Formulare, Dropdowns, Listen) sind aktuell und funktionieren.
2. Deep-Links, Auswahllisten und Referenzwerte im Dashboard stimmen mit den tatsächlichen Werten im Client (z.B. GoRouter-Routes) überein.
3. Keine veralteten oder nicht mehr existierenden Seiten/Optionen werden im Dashboard angeboten.
4. Neue API-Endpunkte oder Client-Seiten, die eine Admin-Bearbeitung erfordern, sind im Dashboard vorhanden.

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
