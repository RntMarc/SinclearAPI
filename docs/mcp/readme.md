# MCP-Server

Der MCP-Server ist Teil der Sinclear Beyond API und stellt die API-Dokumentation
sowie die Möglichkeit zum Erstellen von Rezept-Entwürfen über das
[Model Context Protocol](https://modelcontextprotocol.io) (MCP) mit dem
**Streamable HTTP**-Transport bereit.

**Funktionsumfang:**
- **Ohne Authentifizierung:** Rein lesende Dokumentation (Tool: `get_documentation`)
- **Mit API-Key-Authentifizierung:** Zusätzlich Rezept-Entwürfe erstellen (Tool: `create_recipe_draft`)

## Zugang

| Eigenschaft | Wert |
|-------------|------|
| Endpunkt | `POST {base}/mcp` (eine einzige URL) |
| Basis-URL | `{APP_URL}/api/v2/mcp`, z.B. `https://api.example.com/api/v2/mcp` |
| Auth (Dokumentation) | Keine (öffentliche, rein lesende Dokumentation) |
| Auth (Rezept-Entwürfe) | API-Key im `X-Mcp-Key` Header |
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

## API-Key-Authentifizierung

Für die Nutzung des `create_recipe_draft` Tools ist ein API-Key erforderlich.
Keys werden über die API verwaltet:

| Methode | Endpunkt | Beschreibung |
|---------|----------|-------------|
| `POST` | `/mcp/keys` | Neuen API-Key erstellen |
| `GET` | `/mcp/keys` | Eigene Keys auflisten |
| `DELETE` | `/mcp/keys/{id}` | Key löschen |

**Einschränkungen:**
- Maximal 3 aktive Keys pro Nutzer
- Keys sind 90 Tage gültig
- Der vollständige Key wird **nur einmalig** bei der Erstellung zurückgegeben
- Ablaufende Keys werden wöchentlich automatisch bereinigt (MySQL Event)

**Verwendung im MCP-Endpunkt:**
```
POST /mcp
X-Mcp-Key: <dein-api-key>
Content-Type: application/json
```

Ohne `X-Mcp-Key` Header sind nur die dokumentationsbezogenen Tools verfügbar.
Mit gültigem Key zusätzlich das `create_recipe_draft` Tool.

## Protokoll-Ablauf

Der Client sendet JSON-RPC-2.0-Nachrichten per HTTP-POST an den Endpunkt. Der
Server antwortet entweder mit einem JSON-Objekt (`Content-Type: application/json`)
oder – falls der Client ausschließlich `text/event-stream` akzeptiert – mit einem
SSE-Stream. Notifications (Nachrichten ohne `id`) werden mit `202 Accepted` und
leerer Body bestätigt. Der Server ist **zustandslos** (kein `Mcp-Session-Id`-Header).

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

Die Antwort enthält je nach Authentifizierungsstatus verschiedene Tools:

- **Ohne API-Key:** Nur `get_documentation`
- **Mit API-Key:** `get_documentation` + `create_recipe_draft`

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

### 4. Rezept-Entwurf erstellen (`tools/call`)

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "create_recipe_draft",
    "arguments": {
      "title": "Omas Apfelkuchen",
      "category": "backen",
      "description": "Ein klassischer Apfelkuchen",
      "servings": 8,
      "ingredients": [
        { "amount": 500, "unit": "g", "name": "Äpfel" },
        { "amount": 250, "unit": "g", "name": "Mehl" },
        { "amount": 200, "unit": "g", "name": "Zucker" }
      ],
      "steps": [
        { "category": "vorbereitung", "description": "Äpfel schälen und in Scheiben schneiden" },
        { "category": "hauptgang", "description": "Teig zubereiten und in die Form füllen" }
      ]
    }
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
`location-sharing`, `notifications`, `notifications/types`, `public-transport`,
`subscriptions`, `feedback`, `explore`, `app/updates`. Auflösungsregel
(Groß-/Kleinschreibung egal): `docs/{topic}.md` → `docs/{topic}/readme.md`.

## Tool: `create_recipe_draft`

Erstellt einen neuen Rezept-Entwurf über die Sinclear Beyond API. Erfordert
einen API-Key im `X-Mcp-Key` Header.

| Argument | Typ | Erforderlich | Beschreibung |
|----------|-----|--------------|--------------|
| `title` | string | ja | Name des Rezepts |
| `category` | string | ja | Kategorie (enum: vorspeisen, hauptgerichte, desserts, salate, suppen, backen, fruehstueck, getraenke, sonstiges) |
| `description` | string | nein | Kurze Beschreibung |
| `servings` | integer | nein | Portionen (1-127, Standard: 4) |
| `dietaryTags` | string | nein | Ernährungstaggs (kommagetrennt) |
| `ingredients` | array | nein | Liste der Zutaten |
| `steps` | array | nein | Liste der Zubereitungsschritte |

**Zutat-Objekt:**

| Feld | Typ | Erforderlich | Beschreibung |
|------|-----|--------------|--------------|
| `amount` | number | nein | Menge |
| `unit` | string | ja | Einheit (enum: g, kg, ml, l, tl, el, prise, stk, bund, zehe, scheibe, tasse, dose, packung, tropfen) |
| `name` | string | ja | Name der Zutat |

**Schritt-Objekt:**

| Feld | Typ | Erforderlich | Beschreibung |
|------|-----|--------------|--------------|
| `category` | string | nein | Kategorie (enum: vorbereitung, hauptgang, beilage, garnierung, sonstiges; Standard: sonstiges) |
| `title` | string | nein | Optionale Überschrift |
| `description` | string | ja | Beschreibung des Schritts |

**Antwort (Erfolg):**
```json
{
  "success": true,
  "recipeId": "550e8400-e29b-41d4-a716-446655440000",
  "message": "Rezept-Entwurf erfolgreich erstellt.",
  "recipe": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Omas Apfelkuchen",
    "category": "backen",
    "isDraft": true,
    "createdAt": "2026-08-03 12:00:00"
  }
}
```

**Antwort (Fehler):**
```json
{
  "content": [{ "type": "text", "text": "Validierungsfehler: ..." }],
  "isError": true
}
```

**Verfügbare Einheiten:**

| Wert | Beschreibung |
|------|-------------|
| `g` | Gramm |
| `kg` | Kilogramm |
| `ml` | Milliliter |
| `l` | Liter |
| `tl` | Teelöffel |
| `el` | Esslöffel |
| `prise` | Prise |
| `stk` | Stück |
| `bund` | Bund |
| `zehe` | Zehe |
| `scheibe` | Scheibe |
| `tasse` | Tasse |
| `dose` | Dose |
| `packung` | Packung |
| `tropfen` | Tropfen |

## Quellen

| Quelle | Verwendung |
|--------|-----------|
| `openapi.yaml` (Projektwurzel) | Strukturierte Endpunkt-Übersicht (`topic=openapi`) |
| `docs/*.md` | Volltext-Dokumentation (`topic=<pfad>`) |

Beide Dateien werden bei jedem Aufruf frisch gelesen – die Dokumentation ist
damit immer auf dem aktuellen Stand des Repos.

## Sicherheit

- **Dokumentation:** Öffentlich zugänglich, rein lesend.
- **Rezept-Entwürfe:** Nur mit gültigem API-Key, der dem Nutzer gehört.
- **Key-Management:** Erfordert JWT-Authentifizierung (nur eigene Keys verwalten).
- **.htaccess unverändert wirksam:** Der direkte Dateizugriff auf `docs/`,
  `openapi.yaml` und `.md`/`.yaml`-Dateien bleibt blockiert; die Inhalte werden
  ausschließlich über den PHP-Endpunkt ausgeliefert.
- Der Endpunkt durchläuft die globalen Middlewares (HTTPS-Pflicht,
  Security-Header, CORS).
- API-Keys werden als SHA-256-Hashes gespeichert (nie im Klartext in der DB).

## Implementierung

| Datei | Zweck |
|-------|-------|
| `src/Controllers/McpController.php` | HTTP-Endpunkt `GET/POST /mcp` + Key-Management |
| `src/Services/Mcp/McpServer.php` | JSON-RPC-/MCP-Protokoll (initialize, tools/list, tools/call, ping) |
| `src/Services/Mcp/DocumentationProvider.php` | Themen-Auflösung und Inhaltserzeugung |
| `src/Services/Mcp/OpenApiParser.php` | Leichtgewichtiger Parser für `openapi.yaml` |
| `src/Services/McpApiKeyService.php` | API-Key-Verwaltung (Erstellung, Validierung) |
| `src/Repository/McpApiKeyRepository.php` | Datenbankzugriff für API-Keys |
| `src/Middleware/McpApiKeyMiddleware.php` | Extrahiert und validiert API-Key aus Header |
| `bin/test-mcp.php` | Integrationstest (Handshake + Tool-Aufrufe) |
| `config/routes.php` | Routen-Registrierung (`/mcp`, `/mcp/keys`) |
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
