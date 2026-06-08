# Sinclear Beyond REST API

Zentrale PHP-8.4-REST-API für Sinclear Beyond. Ersetzt direkte Datenbankzugriffe aus Next.js und dient als Backend für Web, Mobile und Chat.

## Voraussetzungen

- PHP 8.4+
- Composer
- MySQL 8 (bestehende Produktionsdatenbank)
- Apache2 mit `mod_rewrite` (empfohlen)

## Installation

```bash
cd api
composer install --no-dev   # Produktion
cp .env.example .env        # Konfiguration anpassen
php bin/migrate.php         # Neue Auth/Chat-Tabellen anlegen
```

## Entwicklungsserver

```bash
php -S localhost:8080 -t public
```

Basis-URL: `http://localhost:8080/api/v1`

## Authentifizierung

Alle Endpunkte (außer Login) erfordern:

```
Authorization: Bearer <accessToken>
```

### Login-Flows

| Methode | Endpunkte |
|---------|-----------|
| E-Mail OTP | `POST /auth/otp/request`, `POST /auth/otp/verify` |
| Passkey | `POST /auth/passkey/login/begin`, `POST /auth/passkey/login/finish` |
| Discord OAuth2+PKCE | `GET /auth/discord/start`, `GET /auth/discord/callback` |
| Token-Refresh | `POST /auth/refresh` |

Access Token: 15 Minuten. Refresh Token: 90 Tage mit Rotation.

## API-Struktur

```
/api/v1/auth/*          – Authentifizierung
/api/v1/users           – Benutzer (CRUD)
/api/v1/events          – Kalenderereignisse
/api/v1/polls           – Umfragen (+ Spezialaktionen)
/api/v1/chat/*          – Chat, Präsenz, SSE
/api/v1/...             – Weitere Ressourcen (siehe openapi.yaml)
```

Vollständige Dokumentation: [openapi.yaml](openapi.yaml) (Swagger UI kompatibel).

## Qualitätssicherung

```bash
composer phpstan
composer phpcs
```

## Dokumentation

- [docs/architecture.md](docs/architecture.md)
- [docs/authentication.md](docs/authentication.md)
- [docs/authorization.md](docs/authorization.md)
- [docs/deployment.md](docs/deployment.md)
- [docs/errors.md](docs/errors.md)
- [docs/client-examples.md](docs/client-examples.md)

## Sicherheit

- Prepared Statements (PDO)
- JWT + Refresh-Token-Rotation
- Rate Limiting
- Security Headers
- DTOs filtern sensitive Felder (`passwordHash`, `code`, …)

## Apache Deployment

Siehe [docs/deployment.md](docs/deployment.md).
