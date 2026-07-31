# MCP-Server (Dokumentation)

Der MCP-Server ist Teil der Sinclear Beyond API und stellt die API-Dokumentation
über das [Model Context Protocol](https://modelcontextprotocol.io) (MCP) mit dem
**Streamable HTTP**-Transport bereit. Er ist ausschließlich dafür gedacht, dass
KI-Clients (z.B. OpenCode) die Dokumentation strukturiert über HTTP/JSON abrufen
können – ohne Authentifizierung und ohne Interaktion mit der API.

## Zugang

| Eigenschaft | Wert |
|-------------|------|
| Endpunkt | `POST {base}/mcp` (eine einzige URL) |
| Basis-URL | `{APP_URL}/api/v2/mcp`, z.B. `https://api.example.com/api/v2/mcp` |
| Auth | Keine (öffentliche, rein lesende Dokumentation) |
| Transport | MCP Streamable HTTP (JSON-RPC 2.0) |
| Protokollversionen | `2025-06-18`, `2025-03-26` |
| GET auf `/mcp` | `405 Method Not Allowed` (kein SSE-Push; laut MCP-Spec erlaubt) |

## Konfiguration in OpenCode

In der OpenCode-Konfiguration (`opencode.json` / `opencode.jsonc`) als Remote-Server
registrieren:

```jsonc
{
  "mcp": {
    "sinclear-docs": {
      "type": "remote",
      "url": "https://api.example.com/api/v2/mcp"
    }
  }
}
```

Danach erscheinen die Tools des Servers unter dem Namen `sinclear-docs*`.

## Protokoll-Ablauf

Der Client sendet JSON-RPC-2.0-Nachrichten per HTTP-POST an den Endpunkt. Der
Server antwortet entweder mit einem JSON-Objekt (`Content-Type: application/json`)
oder – falls der Client ausschließlich `text/event-stream` akzeptiert – mit einem
SSE-Stream. Notifications (Nachrichten ohne `id`) werden mit `202 Accepted` und
leerem Body bestätigt. Der Server ist **zustandslos** (kein `Mcp-Session-Id`-Header).

### 1. Handshake (`initialize`)

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-06-18",
    "capabilities": {},
    "clientInfo": { "name": "opencode", "version": "1.0.0" }
  }
}
```

Antwort enthält die ausgehandelte `protocolVersion`, die `capabilities`
(`tools` mit `listChanged: false`) und `serverInfo`
(`name: sinclear-docs-mcp`, `version`). Anschließend sendet der Client die
Notification `notifications/initialized`.

### 2. Tools abrufen (`tools/list`)

Antwort enthält genau ein Tool: `get_documentation`.

### 3. Dokumentation abrufen (`tools/call`)

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "get_documentation",
    "arguments": { "topic": "openapi", "format": "json" }
  }
}
```

### Weitere Methoden

| Methode | Zweck |
|---------|-------|
| `ping` | Health-Check (Antwort: leeres Result) |
| `notifications/initialized` | Handshake-Abschluss (Notification → 202) |

## Tool: `get_documentation`

| Argument | Typ | Erforderlich | Beschreibung |
|----------|-----|--------------|--------------|
| `topic` | string | ja | Dokumentationsthema (siehe unten) |
| `format` | string | nein | `markdown` (Standard) oder `json` (strukturiert) |

Das Ergebnis ist ein `content`-Array mit einem Text-Eintrag. Bei unbekanntem
Thema wird `isError: true` gesetzt und eine Liste der verfügbaren Themen
zurückgegeben.

### Themen

**Kern-Themen:**

| Topic | Inhalt |
|-------|--------|
| `index` | Übersicht aller verfügbaren Themen (Standard-Dokumentationsstart) |
| `openapi` | Strukturierte Übersicht aller API-Endpunkte aus `openapi.yaml` (Methode, Pfad, Auth, Beschreibung); mit `format=json` als strukturiertes JSON (Info + Endpoints) |
| `mcp` | Diese Datei (`docs/mcp/readme.md`) |
| `cron` | `docs/CRON.md` (Cron-Jobs) |

**Dynamische Themen:** Jede Markdown-Datei in `docs/` ist abrufbar, z.B.
`travel`, `auth/login`, `user`, `calendar`, `recipes`, `forum`,
`location-sharing`, `notifications`, `public-transport`, `subscriptions`,
`feedback`, `explore`, `app/updates`. Auflösungsregel (Groß-/Kleinschreibung
egal): `docs/{topic}.md` → `docs/{topic}/readme.md`.

## Quellen

| Quelle | Verwendung |
|--------|-----------|
| `openapi.yaml` (Projektwurzel) | Strukturierte Endpunkt-Übersicht (`topic=openapi`) |
| `docs/*.md` | Volltext-Dokumentation (`topic=<pfad>`) |

Beide Dateien werden bei jedem Aufruf frisch gelesen – die Dokumentation ist
damit immer auf dem aktuellen Stand des Repos.

## Sicherheit

- **Kein Auth:** Die Dokumentation ist bewusst öffentlich zugänglich.
- **Rein lesend:** Der Server registriert ausschließlich das Tool
  `get_documentation` und bietet keinerlei Schreib- oder API-Zugriff.
- **.htaccess unverändert wirksam:** Der direkte Dateizugriff auf `docs/`,
  `openapi.yaml` und `.md`/`.yaml`-Dateien bleibt blockiert; die Inhalte werden
  ausschließlich über den PHP-Endpunkt ausgeliefert.
- Der Endpunkt durchläuft die globalen Middlewares (HTTPS-Pflicht,
  Security-Header, CORS).

## Implementierung

| Datei | Zweck |
|-------|-------|
| `src/Controllers/McpController.php` | HTTP-Endpunkt `GET/POST /mcp` (Slim-Controller) |
| `src/Services/Mcp/McpServer.php` | JSON-RPC-/MCP-Protokoll (initialize, tools/list, tools/call, ping) |
| `src/Services/Mcp/DocumentationProvider.php` | Themen-Auflösung und Inhaltserzeugung |
| `src/Services/Mcp/OpenApiParser.php` | Leichtgewichtiger Parser für `openapi.yaml` |
| `bin/test-mcp.php` | Integrationstest (Handshake + Tool-Aufrufe) |
| `config/routes.php` | Routen-Registrierung (`/mcp`) |
| `config/dependencies.php` | DI-Registrierung |

## Testen

Auf dem Server (oder gegen die produktive URL) ausführen:

```bash
php bin/test-mcp.php
# oder mit expliziter URL:
php bin/test-mcp.php https://api.example.com/api/v2/mcp
```

Der Test prüft: Handshake (`initialize`), `notifications/initialized`,
`tools/list`, Tool-Aufrufe (`index`, Markdown-Doc, `openapi` als JSON),
Fehlerfälle (unbekanntes Thema → `isError`, unbekannte Methode → `-32601`,
ungültiges JSON → HTTP 400) und `GET` → 405. Exit-Code `0` = alle Tests
bestanden.

## Änderungen an der API

Bei Änderungen an Routen/Controllern, die den MCP-Endpunkt betreffen:
1. `src/Services/Mcp/DocumentationProvider.php` (neue Kern-Themen) anpassen.
2. Diese Datei und `openapi.yaml` (Pfad `/mcp`) aktuell halten.
3. `bin/test-mcp.php` um neue Fälle erweitern.
