# Fehlerbehandlung

## Response-Format

```json
{
  "error": "error_code"
}
```

In Entwicklung (`APP_DEBUG=true`) zusätzlich `"message"`.

## HTTP-Statuscodes

| Status | error-Code | Bedeutung |
|--------|------------|-----------|
| 400 | `bad_request` | Ungültige Eingabe |
| 400 | `invalid_credentials` | Login fehlgeschlagen (generisch) |
| 400 | `invalid_email` | E-Mail-Format ungültig |
| 401 | `unauthorized` | Kein/ungültiger Token |
| 401 | `invalid_token` | JWT ungültig oder abgelaufen |
| 401 | `token_revoked` | JTI auf Blacklist |
| 401 | `refresh_token_reuse_detected` | Token-Rotation-Verletzung |
| 403 | `forbidden` | Keine Berechtigung |
| 404 | `not_found` | Ressource nicht gefunden |
| 409 | `conflict` | Konflikt |
| 429 | `rate_limit_exceeded` | Zu viele Anfragen |
| 500 | `internal_error` | Serverfehler (keine Details in Produktion) |

## Enumeration-Schutz

Login-Fehler verwenden generische Codes (`invalid_credentials`) statt „User nicht gefunden".
