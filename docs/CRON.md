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
| 3 | `pt_refresh_stale_legs` | 5 Minuten | Aktualisiert veraltete PT-Legs mit Echtzeitdaten |

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
