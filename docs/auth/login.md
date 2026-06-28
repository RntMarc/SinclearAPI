# Authentifizierung & Login

Die API v2 verwendet ein **token-basiertes Authentifizierungssystem** ohne Passwörter.
Unterstützt werden zwei Verfahren:

- **E-Mail OTP** – Einmaliger 6-stelliger Zahlencode per E-Mail
- **Discord OAuth2** – Anmeldung via Discord mit Guild-Prüfung + Pairing-Code

Für die **Registrierung** neuer Konten wird ebenfalls Discord OAuth2 verwendet (siehe unten).

## Token-Typen

| Token | Gültigkeit | Zweck |
|-------|-----------|-------|
| **Access-Token** (JWT) | 15 Minuten | Authentifiziert API-Anfragen (Bearer Token) |
| **Refresh-Token** | 90 Tage | Ausstellung neuer Access-Tokens (Rotation) |

Jeder Refresh-Token gehört zu einer **Familie**. Wird ein bereits rotierter Token
erneut verwendet, wird die gesamte Familie widerrufen (Reuse-Detection).

## E-Mail OTP Flow

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant DB
    participant SMTP

    Client->>API: POST /auth/login/otp/request { email }
    API->>DB: User existiert?
    alt User nicht gefunden
        API-->>Client: 404 user_not_found
    else User existiert
        API->>DB: Rate-Limit prüfen
        alt Zu viele Anfragen
            API-->>Client: 429 too_many_requests
        else
            API->>API: 6-stelligen Code generieren
            API->>DB: Code in OtpToken speichern<br/>(expiresAt = now + 10 min)
            API->>SMTP: E-Mail mit Code senden
            API-->>Client: 200 { message: "otp_sent" }
        end
    end

    Note over Client: Nutzer gibt Code aus E-Mail ein

    Client->>API: POST /auth/login/otp/verify { email, code }
    API->>DB: Code in OtpToken suchen<br/>(email + code, unused, not expired)
    alt Code ungültig/abgelaufen
        API-->>Client: 400 invalid_or_expired_code
    else Code gültig
        API->>DB: Code als used markieren
        API->>DB: RefreshTokenFamily + RefreshToken anlegen
        API-->>Client: 200 { refresh_token, expires_at }
    end

    Note over Client: Refresh-Token sicher speichern (Keychain/Keystore)

    Client->>API: POST /auth/refresh { refresh_token }
    API->>DB: Refresh-Token validieren (Hash, nicht revoked, nicht expired)
    alt Token ungültig
        API-->>Client: 401 invalid_refresh_token
    else Token gültig
        API->>DB: Alten Token revoken
        API->>DB: Neuen Refresh-Token (Rotation) + Family-Check
        alt Reuse erkannt
            API->>DB: Ganze Familie revoken
            API-->>Client: 401 invalid_refresh_token
        else
            API-->>Client: 200 { access_token, expires_in, refresh_token, token_type }
        end
    end
```

## Discord OAuth2 Flow

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant DB
    participant Discord

    Client->>API: POST /auth/login/discord/start
    API->>API: PKCE: code_verifier + code_challenge generieren
    API->>DB: State + code_verifier speichern (WebauthnChallenge)
    API-->>Client: 200 { url }

    Note over Client: Browser/Tab mit Discord-URL öffnen

    Client->>Discord: Browser öffnet Discord OAuth-Seite
    Discord->>API: GET /auth/login/discord/callback?code=...&state=...
    API->>DB: State validieren + abrufen
    alt State ungültig/abgelaufen
        API-->>Browser: HTML-Fehlerseite
    else State gültig
        API->>Discord: code + code_verifier + client_secret → Token austauschen
        API->>Discord: GET /users/@me (User-Info abrufen)
        API->>Discord: GET /users/@me/guilds (Guild-Mitgliedschaft prüfen)
        alt Nicht in Guild
            API-->>Browser: HTML-Fehlerseite
        else
            API->>DB: User.findByDiscordId()
            alt Kein User mit dieser Discord-ID
                API-->>Browser: HTML-Fehlerseite (nicht registriert)
            else User gefunden
                API->>DB: 6-stelligen Pairing-Code in OtpToken speichern<br/>(expiresAt = now + 2 min)
                API-->>Browser: HTML-Seite mit Pairing-Code
            end
        end
    end

    Note over Client: Nutzer kopiert Code aus Browser in App

    Client->>API: POST /auth/login/otp/verify { code }
    API->>DB: OtpToken.findValidByCode(code)
    alt Code ungültig/abgelaufen
        API-->>Client: 400 invalid_or_expired_code
    else Code gültig
        API->>DB: Code als used markieren
        API->>DB: RefreshTokenFamily + RefreshToken für den User anlegen
        API-->>Client: 200 { refresh_token, expires_at }
    end

    Note over Client: Refresh-Token sicher speichern

    Client->>API: POST /auth/refresh { refresh_token }
    API-->>Client: 200 { access_token, expires_in, refresh_token, token_type }
```

## Discord OAuth2 Registrierungs-Flow

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant DB
    participant Discord

    Client->>API: POST /auth/register/discord/start
    API->>API: PKCE: code_verifier + code_challenge generieren
    API->>DB: State + code_verifier + "__register__" speichern (WebauthnChallenge)
    API-->>Client: 200 { url }

    Note over Client: Browser/Tab mit Discord-URL öffnen

    Client->>Discord: Browser öffnet Discord OAuth-Seite
    Discord->>API: GET /auth/register/discord/callback?code=...&state=...
    API->>DB: State validieren + abrufen
    alt State ungültig/abgelaufen
        API-->>Browser: HTML-Fehlerseite
    else State gültig
        API->>Discord: code + code_verifier + client_secret → Token austauschen
        API->>Discord: GET /users/@me (User-Info inkl. E-Mail abrufen)
        API->>Discord: GET /users/@me/guilds (Guild-Mitgliedschaft prüfen)
        alt Nicht in Guild
            API-->>Browser: HTML-Fehlerseite (kein Konto, Guild-Mitgliedschaft erforderlich)
        else In Guild
            API->>DB: User.findByDiscordId()
            alt Discord-ID bereits verknüpft
                API-->>Browser: HTML-Fehlerseite (Bereits registriert)
            else Nicht verknüpft
                API->>DB: User.findByEmail(discord_email)
                alt E-Mail bereits registriert
                    API-->>Browser: HTML-Fehlerseite (E-Mail bereits registriert)
                else E-Mail frei
                    API->>DB: Neuen User anlegen (email, displayName, discordId)
                    API->>DB: 6-stelligen Pairing-Code in OtpToken speichern<br/>(expiresAt = now + 2 min)
                    API-->>Browser: HTML-Seite mit Pairing-Code
                end
            end
        end
    end

    Note over Client: Nutzer kopiert Code aus Browser in App

    Client->>API: POST /auth/login/otp/verify { code }
    API->>DB: OtpToken.findValidByCode(code)
    alt Code ungültig/abgelaufen
        API-->>Client: 400 invalid_or_expired_code
    else Code gültig
        API->>DB: Code als used markieren
        API->>DB: RefreshTokenFamily + RefreshToken für den User anlegen
        API-->>Client: 200 { refresh_token, expires_at }
    end

    Note over Client: Refresh-Token sicher speichern

    Client->>API: POST /auth/refresh { refresh_token }
    API-->>Client: 200 { access_token, expires_in, refresh_token, token_type }
```

## API-Endpunkte

| Methode | Pfad | Rate-Limit | Beschreibung |
|---------|------|-----------|-------------|
| `POST` | `/api/v2/auth/login/otp/request` | 10 req/60s | E-Mail-OTP anfordern |
| `POST` | `/api/v2/auth/login/otp/verify` | 10 req/60s | Code verifizieren (OTP + Discord) |
| `POST` | `/api/v2/auth/login/discord/start` | – | Discord OAuth-URL generieren (Login) |
| `GET` | `/api/v2/auth/login/discord/callback` | – | Discord-Redirect verarbeiten (Login) |
| `POST` | `/api/v2/auth/register/discord/start` | – | Discord OAuth-URL generieren (Registrierung) |
| `GET` | `/api/v2/auth/register/discord/callback` | – | Discord-Redirect verarbeiten (Registrierung) |
| `POST` | `/api/v2/auth/refresh` | 10 req/60s | Access-Token erneuern |

### POST /api/v2/auth/login/otp/request

**Request:**
```json
{ "email": "user@example.com" }
```

**Response (200):**
```json
{ "message": "otp_sent" }
```

**Fehler:** `400 invalid_email`, `404 user_not_found`, `429 too_many_requests`

### POST /api/v2/auth/login/otp/verify

**Request (E-Mail-OTP):**
```json
{ "email": "user@example.com", "code": "482731" }
```

**Request (Discord-Pairing):**
```json
{ "code": "482731" }
```

**Response (200):**
```json
{
    "refresh_token": "a1b2c3d4e5f6...",
    "expires_at": 1747353600
}
```

**Fehler:** `400 invalid_code`, `400 invalid_or_expired_code`, `404 user_not_found`

### POST /api/v2/auth/login/discord/start

**Response (200):**
```json
{
    "url": "https://discord.com/api/oauth2/authorize?response_type=code&client_id=..."
}
```

### GET /api/v2/auth/login/discord/callback

**Query-Parameter:** `?code=...&state=...`

**Response (200):** HTML-Seite mit 6-stelligem Pairing-Code.
Der Nutzer kopiert diesen Code in die App und verwendet ihn an `/auth/login/otp/verify`.

### POST /api/v2/auth/register/discord/start

**Response (200):**
```json
{
    "url": "https://discord.com/api/oauth2/authorize?response_type=code&client_id=..."
}
```

### GET /api/v2/auth/register/discord/callback

**Query-Parameter:** `?code=...&state=...`

**Response (200):** HTML-Seite mit 6-stelligem Pairing-Code.
Der Nutzer kopiert diesen Code in die App und verwendet ihn an `/auth/login/otp/verify`.

**Fehler:** `Ungültiger State`, `Discord-Account bereits verknüpft`, `E-Mail bereits registriert`, `Guild-Mitgliedschaft fehlt`

### POST /api/v2/auth/refresh

**Request:**
```json
{ "refresh_token": "a1b2c3d4e5f6..." }
```

**Response (200):**
```json
{
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5...",
    "expires_in": 900,
    "refresh_token": "x7y8z9...",
    "token_type": "Bearer"
}
```

**Fehler:** `400 missing_refresh_token`, `401 invalid_refresh_token`, `404 user_not_found`

## Sicherheitsmechanismen

| Mechanismus | Beschreibung |
|-------------|-------------|
| **HTTPS erforderlich** | Nicht-TLS-Verbindungen werden mit 403 abgewiesen |
| **Rate-Limiting** | 100 req/60s global, 10 req/60s auf Auth-Endpunkten |
| **OTP-Einschränkung** | Max. 3 Code-Anfragen pro E-Mail pro 60 Sekunden |
| **Code-Gültigkeit** | E-Mail-OTP: 10 Min, Discord-Pairing: 2 Min (einmalig) |
| **Refresh-Rotation** | Jeder Refresh wird rotiert; alte Tokens werden ungültig |
| **Reuse-Detection** | Bei Wiederverwendung eines alten Refresh-Tokens → gesamte Familie gesperrt |
| **Discord PKCE** | code_verifier + state verhindern CSRF/Callback-Faking |
| **Guild-Prüfung** | Nur Mitglieder der konfigurierten Discord-Guild können sich anmelden oder registrieren |
| **Registrierungsprüfung** | Discord-ID darf noch nicht verknüpft sein, E-Mail darf noch nicht registriert sein |
| **CORS** | Nur konfigurierte Origins werden akzeptiert |

## Datenbank-Tabellen

| Tabelle | Verwendung |
|---------|-----------|
| `User` | Nutzerstammdaten (email, discordId, displayName, isAdmin) |
| `OtpToken` | 6-stellige Codes (E-Mail-OTP + Discord-Pairing) |
| `RefreshToken` | Gehashte Refresh-Tokens mit Family-Zuordnung |
| `RefreshTokenFamily` | Gruppen zusammengehöriger Refresh-Tokens |
| `JtiBlacklist` | Widerrufene Access-Token-JTIs |
| `WebauthnChallenge` | Discord-State/PKCE-Zwischenspeicher |

## Client-Implementierungshinweise

1. **Token-Speicherung:** Refresh-Token sicher speichern (iOS Keychain, Android EncryptedSharedPreferences, Flutter: flutter_secure_storage).
2. **Access-Token-Erneuerung:** Vor jeder API-Anfrage prüfen, ob der Access-Token abgelaufen ist und ggf. über `/auth/refresh` erneuern.
3. **401-Handling:** Bei einer 401-Antwort den Refresh-Token erneuern versuchen; schlägt auch das fehl, den Nutzer ausloggen.
4. **Discord-Login:** Den Discord-OAuth-URL in einem System-Browser öffnen (nicht WebView), da Discord sonst den Login blockieren kann.
5. **Fehlerbehandlung:** Auf `429`-Antworten mit exponentiellem Backoff reagieren.
