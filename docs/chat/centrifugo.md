# Centrifugo Server – Deploy-Handbuch (Schritt 3)

Dieses Dokument beschreibt die Centrifugo-Server-Konfiguration für den produktiven Einsatz.

## Domain

- **Hauptdomain:** `chat.sinclear.de`
- **WebSocket:** `wss://chat.sinclear.de/connection/websocket`
- **SSE Fallback:** `https://chat.sinclear.de/connection/uni_sse` / `/connection/http_stream`

## Docker

```bash
docker run -d \
  --name centrifugo \
  --restart unless-stopped \
  -p 8000:8000 \
  -v /path/to/config.json:/centrifugo/config.json \
  centrifugo/centrifugo:v6
```

## config.json

Config-Vorschlag mit Claude überarbeitet, Original war für alte Centrifugo-Version und vom Format her komplett falsch.

```json
{
  "log": {
    "level": "warning",
    "file": "/var/log/centrifugo/centrifugo.log"
  },
  "admin": {
    "enabled": true,
    "password": "xxxx",
    "secret": "xxxx"
  },
  "engine": {
    "type": "memory"
  },
  "http_server": {
    "address": "0.0.0.0",
    "port": 8000
  },
  "http_api": {
    "key": "xxxx"
  },
  "client": {
    "allowed_origins": [
      "https://sinclear.de",
      "https://*.sinclear.de"
    ],
    "concurrency": 8,
    "token": {
      "hmac_secret_key": "xxxx",
      "issuer": "sinclear-api",
      "audience": "centrifugo"
    }
  },
  "channel": {
    "namespaces": [
      {
        "name": "chat",
        "subscribe_proxy_enabled": true,
        "publish_proxy_enabled": true,
        "presence": true,
        "join_leave": true,
        "force_push_join_leave": true,
        "force_recovery": true,
        "force_positioning": true,
        "history_size": 100,
        "history_ttl": "600s",
        "channel_regex": "^[A-Za-z0-9_-]+$",
        "publication_data_format": "json"
      },
      {
        "name": "user",
        "subscribe_proxy_enabled": true,
        "presence": true,
        "join_leave": true,
        "force_push_join_leave": true,
        "channel_regex": "^[A-Za-z0-9_-]+$",
        "publication_data_format": "json"
      }
    ],
    "proxy": {
      "subscribe": {
        "endpoint": "https://sinclear.de/api/v2/centrifugo/subscribe",
        "http": {
          "static_headers": { "X-Centrifugo-Proxy-Key": "xxxx" }
        }
      },
      "publish": {
        "endpoint": "https://sinclear.de/api/v2/centrifugo/publish",
        "http": {
          "static_headers": { "X-Centrifugo-Proxy-Key": "xxxx" }
        }
      }
    }
  }
}
```

### Erklärung der wichtigsten Felder

| Feld | Beschreibung |
|---|---|
| `engine: "memory"` | Single-Node, ~10 Nutzer, kein Redis nötig |
| `chat.subscribe_proxy_enabled` | Centrifugo ruft PHP-API für Subscribe-Validierung auf |
| `chat.publish_proxy_enabled` | Centrifugo ruft PHP-API für Publish-Validierung auf (Typing) |
| `chat.presence` | Ermöglicht Presence-Abfragen via Server-API |
| `chat.force_recovery` | Clients erhalten verpasste Nachrichten bei Reconnect |
| `chat.force_positioning` | Position-Tracking für zuverlässige Zustellung |
| `chat.history_size: 100` | Letzte 100 Nachrichten pro Channel im Speicher |
| `chat.history_ttl: "600s"` | History wird nach 10 Minuten geleert |
| `user.subscribe_proxy_enabled` | Centrifugo ruft PHP-API für User-Presence-Subscribe auf |
| `user.presence` | Ermöglicht Presence-Abfragen für User-Presence-Channels |
| `http_api.key` | API-Key für Server-API (Publish, Presence, etc.) |
| `client.token_hmac_secret_key` | Secret für Connection-JWT-Validierung |
| `http_api.static_headers` | Wird bei jedem Proxy-Request an PHP-API gesendet |

## Reverse Proxy

### Traefik (Primär – Homeserver)

Traefik handhabt WebSocket-Upgrade automatisch. Keine spezielle WS-Konfiguration nötig.

```yaml
# docker-compose Labels für centrifugo:8000
- "traefik.enable=true"
- "traefik.http.routers.centrifugo.rule=Host(`chat.sinclear.de`)"
- "traefik.http.routers.centrifugo.entrypoints=websecure"
- "traefik.http.routers.centrifugo.tls.certresolver=letsencrypt"
- "traefik.http.services.centrifugo.loadbalancer.server.port=8000"
```

*Alternative:* Caddy/nginx (Beispiele siehe unten).

### Caddy (Alternative)

```
chat.sinclear.de {
    reverse_proxy localhost:8000

    header {
        Access-Control-Allow-Origin "https://app.sinclear.de"
        Access-Control-Allow-Methods "GET, POST, OPTIONS"
        Access-Control-Allow-Headers "Authorization, Content-Type"
    }
}
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name chat.sinclear.de;

    ssl_certificate /etc/letsencrypt/live/chat.sinclear.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/chat.sinclear.de/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }
}
```

## Secrets

| Secret | Beschreibung | Wo hinterlegen |
|---|---|---|
| `CENTRIFUGO_API_KEY` | API-Key für Server-API | `.env` auf API-Server + `config.json` auf Centrifugo |
| `CENTRIFUGO_HMAC_SECRET` | Secret für Connection-JWTs (HS256) | `.env` auf API-Server + `config.json` auf Centrifugo |
| `CENTRIFUGO_PROXY_KEY` | Shared Secret für Proxy-Endpoints | `.env` auf API-Server + `config.json` in `static_headers` |

**Wichtig:** `CENTRIFUGO_API_KEY` und `CENTRIFUGO_PROXY_KEY` können identisch sein, müssen aber nicht. Für maximale Sicherheit sollten sie unterschiedlich sein.

**Generierung:** Alle drei Secrets mit `openssl rand -hex 32` erzeugen. Einmal in `.env` (API-Server), einmal in `config.json` (Centrifugo-Host) eintragen.

## Engine

- **Memory** (Single-Node): Ausreichend für ~10 gleichzeitige Nutzer
- Redis optional für HA (nicht benötigt)
- Kein Persistence-Backend nötig – API-DB ist Source of Truth

## Monitoring

- **Info-Endpoint:** `GET https://chat.sinclear.de/api/info`
- **Health:** Prüfe ob WebSocket-Verbindung funktioniert
- **Metriken:** Optional via PRO Analytics

## Checklist vor Deploy

- [ ] `CENTRIFUGO_API_KEY` generiert (`openssl rand -hex 32`) und in `.env` + `config.json` eingetragen
- [ ] `CENTRIFUGO_HMAC_SECRET` generiert (`openssl rand -hex 32`) und in `.env` + `config.json` eingetragen
- [ ] `CENTRIFUGO_PROXY_KEY` generiert (`openssl rand -hex 32`) und in `.env` + `config.json` eingetragen
- [ ] Alle drei Secrets sind **unterschiedlich**
- [ ] `CENTRIFUGO_WS_URL` in `.env` auf `wss://chat.sinclear.de/connection/websocket` gesetzt
- [ ] `CENTRIFUGO_API_URL` in `.env` auf `https://chat.sinclear.de/api` gesetzt
- [ ] Bestehende `config.json` auf Centrifugo-Host mit Doku-Version oben überschreiben (nicht manuell anpassen!)
- [ ] Traefik-Labels/Router konfiguriert (`chat.sinclear.de` → `:8000`, TLS via Let's Encrypt)
- [ ] `allowed_origins`: `["https://sinclear.de", "https://app.sinclear.de", "https://*.sinclear.de"]` (kein `*`!)
- [ ] Proxy-Endpoints erreichbar (`POST /api/v2/centrifugo/subscribe`, `POST /api/v2/centrifugo/publish`)
- [ ] `CENTRIFUGO_ENABLED=true` in `.env` auf API-Server gesetzt
- [ ] Test: `GET https://chat.sinclear.de/api/info` → 200
- [ ] Test: Client verbindet via WebSocket und erhält Token
- [ ] Test: Nachricht wird via REST gesendet und via WebSocket empfangen
- [ ] Test: Typing-Event wird via WebSocket gesendet und validiert
- [ ] Test: PWA (`https://sinclear.de`) kann WebSocket-Verbindung aufbauen
- [ ] Test: Android-App kann WebSocket-Verbindung aufbauen (ohne Origin-Header)
