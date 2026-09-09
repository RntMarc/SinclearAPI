# Cron-Jobs / Geplante Aufgaben

Übersicht aller Cron-Jobs und geplanten Aufgaben, die regelmäßig ausgeführt werden müssen.

## Architektur

Das Projekt verwendet eine zentrale `bin/cron.php`, die als Taktgeber dient:
- Wird über **System-Cron** regelmäßig aufgerufen (z.B. alle 5 Minuten)
- Prüft selbstständig, welche Tasks ausstehend sind
- Führt nur die notwendigen Tasks aus (basierend auf `CronSchedule`-Tabelle)
- Keine direkte HTTP-Erreichbarkeit (nicht im `public/`-Verzeichnis)

```cron
# System-Cron: Alle 5 Minuten aufrufen
*/5 * * * * php /path/to/project/bin/cron.php >> /path/to/project/var/log/cron.log 2>&1
```

## Task-Registrierung

Tasks werden in `bin/cron.php` registriert. Um einen neuen Task hinzuzufügen:

1. Erstelle eine neue Klasse in `src/Services/Cron/Tasks/` die `CronTaskInterface` implementiert
2. Registriere den Task in `bin/cron.php` mit `$scheduler->register(new DeinTask())`

## Übersicht aller Tasks

| # | Bezeichnung | Intervall | Beschreibung |
|---|-------------|-----------|--------------|
| 1 | `cleanup_otp_tokens` | 1 Stunde | Löscht abgelaufene und benutzte OTP-Codes |
| 2 | `cleanup_location_sharing` | 24 Stunden | Bereinigt alte Location-Sharing-Sessions |
| 3 | `cleanup_direct_messages` | 24 Stunden | Löscht Chat-Nachrichten älter als 90 Tage |
| 4 | `pt_refresh_stale_legs` | 5 Minuten | Aktualisiert veraltete PT-Legs mit Echtzeitdaten |
| 5 | `cleanup_external_data_cache` | 24 Stunden | Entfernt abgelaufene Cache-Einträge (ExternalDataCache) |
| 6 | `cleanup_stale_push_subscriptions` | 24 Stunden | Löscht tote Push-Subscriptions (10+ Fehlversuche oder 90 Tage ohne Lebenszeichen) |

## Details

### OTP Cleanup
- **Task-Name:** `cleanup_otp_tokens`
- **Intervall:** 3600 Sekunden (1 Stunde)
- **Aktion:** `DELETE FROM OtpToken WHERE expiresAt < NOW()`

### Location Sharing Cleanup
- **Task-Name:** `cleanup_location_sharing`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** Löscht Sessions ohne Location-Updates seit >7 Tagen (mit zugehörigen Locations und Recipients)

### PT Refresh Stale Legs
- **Task-Name:** `pt_refresh_stale_legs`
- **Intervall:** 300 Sekunden (5 Minuten)
- **Aktion:** Ruft für alle Legs mit `tripId`, deren `lastCheckedAt` älter als 5 Minuten ist, aktuelle Echtzeitdaten von Transitious `/v6/trip` ab und aktualisiert `actualDeparture`, `actualArrival`, `departureDelay`, `arrivalDelay`, `departurePlatform`, `arrivalPlatform`, `cancelled`, `realTimeState` und `lastCheckedAt`.
- **Rate-Limit:** Max. 8 Legs pro Batch, 2 Sekunden Pause zwischen den Batches.
- **Datei:** `src/Services/Cron/Tasks/PtRefreshStaleLegsTask.php`

### Chat Cleanup
- **Task-Name:** `cleanup_direct_messages`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** Löscht `DirectMessage`- und `ChatEvent`-Einträge älter als 90 Tage in Batches (LIMIT 1000, um lange Locks zu vermeiden). Räumt verwaiste `ChatConversation`-Einträge (keine Nachrichten, älter als 1 Tag), abgelaufene `ChatPresence`- und `ChatTyping`-Einträge auf.
- **Datei:** `src/Services/Cron/Tasks/CleanupOldDirectMessagesTask.php`

### External Data Cache Cleanup
- **Task-Name:** `cleanup_external_data_cache`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** `DELETE FROM ExternalDataCache WHERE expires_at < NOW()` — entfernt alle abgelaufenen Cache-Einträge für externe Datenquellen (Wetter, Warnungen, Pollen/UV, Luftqualität).
- **Datei:** `src/Services/Cron/Tasks/CleanupExternalDataCacheTask.php`

### Stale Push Subscriptions Cleanup
- **Task-Name:** `cleanup_stale_push_subscriptions`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** Löscht tote `PushSubscription`-Einträge in Batches (LIMIT 1000):
  1. Dauerhaft fehlgeschlagene Endpoints: `consecutiveFailures >= 10` (z.B. 403, DNS-Fehler, Timeouts — 410/404 werden bereits reaktiv beim Versand gelöscht).
  2. Veraltete Endpoints: `lastSeenAt` älter als 90 Tage **und** kein erfolgreicher Versand (`lastSuccessAt`) in diesem Zeitraum. Die 90-Tage-Übergangsfrist schützt nur vorübergehend offline Geräte — Clients re-registrieren bei App-Start/Login/Resume (`POST /notifications/push-subscription` als Upsert) und setzen damit `lastSeenAt` und den Failure-Zähler zurück. Die Frist entspricht der Refresh-Token-Lebensdauer (90 Tage).
- **Datei:** `src/Services/Cron/Tasks/CleanupStalePushSubscriptionsTask.php`
- **Hintergrund:** Siehe [notifications/readme.md](./notifications/readme.md) → „Bereinigung toter Subscriptions".

## CronSchedule-Tabelle

Die `CronSchedule`-Tabelle (in `events/cron_schedule_schema.sql`) speichert den Status jedes Tasks:

```sql
CREATE TABLE CronSchedule (
  taskName VARCHAR(191) PRIMARY KEY,
  lastRunAt DATETIME(3) NULL,
  lastDurationMs INT NULL,
  lastStatus ENUM('success','failed') NULL,
  lastError TEXT NULL,
  createdAt DATETIME(3) NOT NULL
);
```

## CLI-Ausgabe

`bin/cron.php` gibt eine strukturierte Ausgabe aus:

```
[success] cleanup_otp_tokens — erfolgreich (12ms)
[success] pt_journeys_refresh — erfolgreich (342ms)
Keine Tasks ausstehend.
```

## Hinweise

- **CronSchedule-Tabelle:** Muss initialisiert werden (`events/cron_schedule_schema.sql` ausführen)
- **Logging:** Cron-Jobs loggen nach `var/log/app.log` und `var/log/cron.log`
- **Fehlerbehandlung:** Fehlgeschlagene Tasks werden in `CronSchedule.lastError` protokolliert
- **Neue Tasks:** Immer in `bin/cron.php` registrieren und in `docs/CRON.md` dokumentieren
