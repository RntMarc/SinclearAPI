# MCP-Server – Regeln und Wartung

Der MCP-Server (`sinclear-docs-mcp`) stellt die Dokumentation der Sinclear Beyond API über das Tool `get_documentation` bereit. Alle Dateien im `docs/`-Ordner werden dynamisch gescannt und als Topics verfügbar gemacht.

## Funktionsweise

### Topic-Auflösung

Der MCP-Server scannt `docs/` rekursiv nach `.md`-Dateien. Jede gefundene Datei wird als Topic verfügbar:

| Datei | Topic-Name |
|-------|------------|
| `docs/travel/readme.md` | `travel` |
| `docs/CRON.md` | `cron` |
| `docs/app/updates.md` | `app/updates` |

**Naming-Regel:** Der Topic-Name ergibt sich aus dem Pfad relativ zu `docs/`, ohne `.md`-Endung, in Kleinbuchstaben.

### Spezielle Topics

| Topic | Quelle | Beschreibung |
|-------|--------|--------------|
| `index` | Generiert | Übersicht aller verfügbaren Topics |
| `openapi` | `openapi.yaml` | Strukturierte OpenAPI-Übersicht |
| `mcp` | `docs/mcp/readme.md` | MCP-Server-Dokumentation |
| `cron` | `docs/CRON.md` | Cron-Job-Übersicht |

### Datei-Resolution

Wenn ein Topic angefordert wird, versucht der Server drei Kandidaten:
1. Exakter Pfad (z.B. `notifications/list`)
2. Mit `.md`-Endung (z.B. `notifications/list.md`)
3. Als `readme.md` im Unterverzeichnis (z.B. `notifications/list/readme.md`)

### Tool-Schema (`get_documentation`)

Die `inputSchema` des Tools `get_documentation` enthält **alle** verfügbaren Topics als `enum`-Werte, nicht nur die Kern-Themen. Das `enum` wird zur Laufzeit aus dem `docs/`-Scan generiert (`DocumentationProvider::availableTopics()`), damit MCP-Clients jede Datei direkt anfragen können.

**Wichtig:** Das `enum` darf **nie** auf die Kern-Themen (`index`, `openapi`, `mcp`, `cron`) beschränkt werden. Viele MCP-Clients validieren die Argumente streng gegen das `enum` und blockieren sonst gültige Anfragen (z.B. `notifications/list`).

## Regeln für Entwickler

### Regel 1: Dokumentation bei API-Änderungen
Nach jeder Änderung an der API (Routes, Controllers, DTOs, Services) MUSS sichergestellt werden, dass:
1. Die betroffene Dokumentationsdatei in `docs/` aktualisiert wird.
2. Die Datei über den MCP-Server erreichbar ist (`.md`-Endung, korrekter Pfad).
3. Das `index`-Topic die Datei korrekt auflistet (automatisch durch Scan).

### Regel 2: Neue Dokumentationsdateien
Wenn eine neue Datei in `docs/` angelegt wird:
1. Muss sie die `.md`-Endung haben.
2. Wird sie automatisch vom MCP-Server als Topic verfügbar gemacht.
3. Sollte der Dateiname deskriptiv und in Kleinbuchstaben sein (z.B. `notifications/list.md`).
4. Unterordner sind erlaubt und erwünscht (z.B. `travel/readme.md`).

### Regel 3: Topic-Names in der Dokumentation
Wenn in anderer Dokumentation auf MCP-Topics verwiesen wird, muss der korrekte Topic-Name verwendet werden:
- `notifications/list` für die Benachrichtigungs-Übersicht
- `cron` für Cron-Jobs
- `travel` für Reise-Dokumentation
- etc.

### Regel 4: Keine hardcoded Topic-Lists
Der MCP-Server verwendet **keine** hardcoded Liste von Topics. Alle Topics werden zur Laufzeit aus dem `docs/`-Ordner gescannt. Es ist daher nicht nötig, neue Topics manuell zu registrieren.

### Regel 5: Index-Topic
Das `index`-Topic wird dynamisch generiert und listet automatisch alle verfügbaren Topics auf. Es muss nicht manuell aktualisiert werden.

### Regel 6: AGENTS.md Referenz
Die `AGENTS.md`-Datei muss immer einen Verweis auf das `MCP.md`-Dokument enthalten, damit Entwickler die Regeln für die MCP-Server-Pflege kennen.

## Regelmäßige Prüfung

Bei jeder Änderung an der Dokumentation oder am MCP-Server sollte geprüft werden:
1. Sind alle `docs/`-Dateien über den MCP-Server erreichbar?
2. Wird das `index`-Topic korrekt generiert?
3. Funktioniert die Topic-Resolution für alle Dateien?
4. Ist das `enum` des Tools `get_documentation` dynamisch (`availableTopics()`), nicht auf Kern-Themen beschränkt?
5. Enthält `AGENTS.md` den Verweis auf `MCP.md`?
6. Test via `php bin/test-mcp.php` (gegen laufende API) ausgeführt?

## Fehlerbehandlung

Wenn ein unbekanntes Topic angefordert wird, gibt der Server eine Fehlermeldung mit einer Liste der verfügbaren Topics aus. Die ersten 40 Topics werden angezeigt.
