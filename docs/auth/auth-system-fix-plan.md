# Auth-System-Sanierungsplan

> Ursachenanalyse und schrittweiser Sanierungsplan für die berichteten Probleme
> (plötzlicher Logout, plötzliche Ablehnung aller Anfragen, „Geister-Re-Login“).

## Diagnose (Kurzfassung)

Die API implementiert bei `POST /auth/refresh` eine strikte Single-Use-Rotation
ohne Kulanzfenster (`TokenService::rotateRefreshToken`). Der Client ruft diesen
Endpunkt jedoch aus **mehreren konkurrierenden Isolates** heraus auf
(Foreground-Service alle 5 Min, WorkManager alle 15 Min, Widget-Sync bei
App-Pause), jeweils mit einem veralteten `SharedPreferences`-Cache. Jede
parallel verlorene/doppelte Rotation endet in `401 invalid_refresh_token` und
damit im automatischen `logout()` des Haupt-Isolates.

Zusätzlich fehlen auf Server-Seite echte Reuse-Detection, Transaktionsschutz,
OTP-Rate-Limits auf mehreren Endpunkten und ein Logout-Endpunkt.

## Leitprinzipien

1. **Client:** genau eine Stelle ruft `/auth/refresh` auf; alle Isolates teilen
   sich einen zwischengespeicherten Access-Token statt ständig zu rotieren.
2. **Server:** Rotation mit Grace-Period + echter Reuse-Detection (Familien-Sperre),
   atomar (Transaktion + Row-Lock).
3. **Doku/Tests** ziehen jede Änderung nach sich (AGENTS.md).

## Umsetzungsreihenfolge

### Phase 1 — API: Refresh-Rotation robust machen
1. Schema-Migration: `RefreshToken.previousTokenHash`, `RefreshToken.graceUntil`,
   `OtpToken.attempts`.
2. `RefreshTokenRepository`: `findValidToken` mit `FOR UPDATE`,
   `findGraceEligibleSuccessor`, Cleanup-Methoden.
3. `TokenService::rotateRefreshToken`: Grace-Period + Reuse-Detection + Transaktion.
4. `AuthController::refresh`: `expires_at` in Response.
5. Neuer Endpunkt `POST /auth/logout` (Revoke + JTI-Blacklist).

### Phase 2 — API: OTP-/Login-Sicherheit
6. Fehlversuchszähler (`attempts`, max. 5) für alle OTP-Verify-Endpunkte.
7. `LoginThrottleMiddleware` auf `/admin/login/otp/*`,
   `/admin-api/auth/otp/*`, `/user/me/email/verify`, `/user/me/discord/verify`.
8. Discord-Pairing-Code an eine Session-ID binden.

### Phase 3 — API: Cleanup & Infrastruktur
9. Cron-Tasks für `RefreshToken`/`RefreshTokenFamily`/`JtiBlacklist`.
10. `RateLimiter`: atomarer Dateizugriff + gedrosseltes `cleanExpired`.

### Phase 4 — API: Vertrag & Doku
11. `openapi.yaml`, `docs/auth/login.md`, `docs/CRON.md`, `.env.example`,
    `config/settings.php`.

### Phase 5 — Client: Token-Management
12. `TokenStorage` + `token_refresh`: Access-Token-Cache, Cross-Isolate-Lock,
    `prefs.reload()`.
13. `AuthService`: Clock-Skew-Puffer, Logout stoppt Hintergrund-Services +
    Session-Marker.
14. `ApiClient`: 401-Refresh-Handler, kein Retry auf `/auth/refresh`.
15. `background_poller`/`widget_data_sync`: keine Doppel-Rotation.
16. `flutter_secure_storage` für Refresh-Token.

### Phase 6 — Tests
17. Unit-Tests für Grace-Period, Reuse-Detection, OTP-Versuche, Token-Manager.
