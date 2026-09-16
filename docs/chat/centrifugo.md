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

```json
{
  "log_level": "warning",
  "log_file": "/var/log/centrifugo/centrifugo.log",
  "admin": false,
  "engine": "memory",
  "api_key": "<CENTRIFUGO_API_KEY>",
  "allowed_origins": [
    "https://app.sinclear.de",
    "https://*.sinclear.de"
  ],
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
    }
  ],
  "http_api": {
    "address": "0.0.0.0",
    "port": 8000,
    "key": "<CENTRIFUGO_API_KEY>",
    "static_headers": {
      "X-Centrifugo-Proxy-Key": "<CENTRIFUGO_PROXY_KEY>"
    }
  },
  "client": {
    "concurrency": 8,
    "token_issuer": "sinclear-api",
    "token_audience": "centrifugo",
    "token_hmac_secret_key": "<CENTRIFUGO_HMAC_SECRET>"
  },
  "proxy": {
    "subscribe_endpoint": "https://api.sinclear.de/api/v2/centrifugo/subscribe",
    "publish_endpoint": "https://api.sinclear.de/api/v2/centrifugo/publish"
  }
}
```

### Erklärung der wichtigsten Felder

| Feld | Beschreibung |
|---|---|
| `engine: "memory"` | Single-Node, ~10 Nutzer, kein Redis nötig |
| `subscribe_proxy_enabled` | Centrifugo ruft PHP-API für Subscribe-Validierung auf |
| `publish_proxy_enabled` | Centrifugo ruft PHP-API für Publish-Validierung auf (Typing) |
| `presence` | Ermöglicht Presence-Abfragen via Server-API |
| `force_recovery` | Clients erhalten verpasste Nachrichten bei Reconnect |
| `force_positioning` | Position-Tracking für zuverlässige Zustellung |
| `history_size: 100` | Letzte 100 Nachrichten pro Channel im Speicher |
| `history_ttl: "600s"` | History wird nach 10 Minuten geleert |
| `http_api.key` | API-Key für Server-API (Publish, Presence, etc.) |
| `client.token_hmac_secret_key` | Secret für Connection-JWT-Validierung |
| `http_api.static_headers` | Wird bei jedem Proxy-Request an PHP-API gesendet |

## Reverse Proxy

### Caddy

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

## Engine

- **Memory** (Single-Node): Ausreichend für ~10 gleichzeitige Nutzer
- Redis optional für HA (nicht benötigt)
- Kein Persistence-Backend nötig – API-DB ist Source of Truth

## Monitoring

- **Info-Endpoint:** `GET https://chat.sinclear.de/api/info`
- **Health:** Prüfe ob WebSocket-Verbindung funktioniert
- **Metriken:** Optional via PRO Analytics

## Checklist vor Deploy

- [ ] `CENTRIFUGO_API_KEY` generiert und in `.env` + `config.json` eingetragen
- [ ] `CENTRIFUGO_HMAC_SECRET` generiert und in `.env` + `config.json` eingetragen
- [ ] `CENTRIFUGO_PROXY_KEY` generiert und in `.env` + `config.json` eingetragen
- [ ] `CENTRIFUGO_WS_URL` in `.env` auf `wss://chat.sinclear.de/connection/websocket` gesetzt
- [ ] `CENTRIFUGO_API_URL` in `.env` auf `https://chat.sinclear.de/api` gesetzt
- [ ] Reverse Proxy konfiguriert (TLS, WebSocket-Upgrade)
- [ ] CORS `allowed_origins` korrekt (`https://app.sinclear.de`)
- [ ] Proxy-Endpoints erreichbar (`POST /api/v2/centrifugo/subscribe`, `POST /api/v2/centrifugo/publish`)
- [ ] `CENTRIFUGO_ENABLED=true` in `.env` auf API-Server gesetzt
- [ ] Test: Client verbindet via WebSocket und erhält Token
- [ ] Test: Nachricht wird via REST gesendet und via WebSocket empfangen
- [ ] Test: Typing-Event wird via WebSocket gesendet und validiert
