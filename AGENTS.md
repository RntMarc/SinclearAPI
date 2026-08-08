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

## Benachrichtigungen

Das Benachrichtigungssystem (`NotificationService` / `PushService`) löst In-App-Benachrichtigungen (Datenbank + optionaler FCM-Push) bei bestimmten Geschäftsvorgängen aus. Die zentrale Übersicht aller Trigger – implementiert und geplant – findet sich in `docs/notifications/list.md`.

### Regeln für Benachrichtigungen

Jeder Entwickler, der eine Funktion implementiert, die Benachrichtigungen auslösen kann, MUSS die folgenden Regeln einhalten:

**Regel 1: Self-Notification-Ausschluss**
Benachrichtigungen werden **nie** an den auslösenden Nutzer selbst gesendet. Jede Logik muss den handelnden Nutzer (`$userId` / Actor) von der Empfängerliste ausschließen.

**Regel 2: Inhaltseigentümer-Prinzip**
Wird auf einen Inhalt reagiert (Kommentar, Bewertung, Upvote), wird **der Eigentümer des Ursprungs-Inhalts** benachrichtigt, nicht alle Beteiligten. Ausnahme: Bei Sammel-Features (Foren, Reise-Foren) werden alle Mitglieder informiert (siehe Regel 3).

**Regel 3: Gruppenzugehörigkeit bei Sammel-Features**
Bei Funktionen mit gemeinsamem Raum (Forum, Abo) werden **alle aktiven Mitglieder** benachrichtigt, sofern sie dies nicht deaktiviert haben. Das `notificationsEnabled`-Flag ist zu respektieren.
**Ausnahme Reisen:** Im verknüpften Forum einer Reise wird **immer** benachrichtigt – das `notificationsEnabled`-Flag wird dort nicht berücksichtigt, da Reise-Beiträge für alle Teilnehmenden relevant sind.

**Regel 4: Kommentar-Ketten-Logik**
Bei Kommentaren mit Thread-Struktur wird die Kette **aufwärts** durchlaufen: Alle Eltern-Kommentare werden benachrichtigt, inklusive des Autors des Ursprungs-Beitrags. Der kommentierende Nutzer selbst wird dabei nie benachrichtigt.

**Regel 5: Statusänderungen**
Bei Statusänderungen (Submission genehmigt/abgelehnt, Moderationsanfrage bearbeitet, Feedback-Vorschlag aktualisiert) wird **ausschließlich der Eigentümer des betroffenen Objekts** benachrichtigt.

**Regel 6: Kollaborative Änderungen**
Bei Änderungen an kollaborativen Objekten (Kalender-Events, Reise-Events, Reise-Unterkünfte) werden **nur die direkt Beteiligten** benachrichtigt, die nicht der Handelnde sind. Bei Reisen sind das die Teilnehmer des betroffenen Events oder der betroffenen Unterkunft, **nicht** alle Reise-Teilnehmer.

**Regel 7: Payload-Konvention**
Jede Benachrichtigung enthält im `payload` mindestens:
- Die ID des betroffenen Objekts (z.B. `postId`, `recipeId`, `tripId`)
- Einen Anzeigenamen des handelnden Nutzers (`actorDisplayName` oder equivalent)
- Alle für die Client-Darstellung und Deep-Linking relevanten Felder

Das `code`-Format lautet immer `domain.action` (z.B. `forum.post_commented`).

**Regel 8: Try/Catch mit Logging**
Jeder Benachrichtigungsaufruf ist in einen `try/catch`-Block zu kapseln. Fehler beim Versand dürfen die Kernaktion (z.B. Kommentar erstellen) nicht behindern. Fehler sind via `error_log()` zu protokollieren.

**Regel 9: Keine Duplikate**
Pro Ereignis wird maximal **eine** Benachrichtigung pro Empfänger erstellt.

**Regel 10: Lesezeichen/Bookmarks**
Lesezeichen lösen **niemals** Benachrichtigungen aus – weder am bookmarkten Inhalt noch anderswo.

**Regel 11: Dokumentation**
Alle existierenden Notification-Codes sind in `docs/notifications/list.md` zu dokumentieren, inklusive Empfängerlogik und Implementierungsstatus. Nach jeder Änderung an der Benachrichtigungslogik MUSS diese Datei aktualisiert werden.

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
