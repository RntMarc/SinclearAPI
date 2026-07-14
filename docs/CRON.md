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
| 2 | `cleanup_notifications` | 24 Stunden | Löscht Notifications älter als 30 Tage |
| 3 | `cleanup_location_sharing` | 24 Stunden | Bereinigt alte Location-Sharing-Sessions |
| 4 | `pt_stations_refresh` | 24 Stunden | Aktualisiert den lokalen Stationen-Cache |
| 5 | `pt_journeys_refresh` | 15 Minuten | Aktualisiert Verspätungen/Ausfälle für offene Fahrten |

## Details

### OTP Cleanup
- **Task-Name:** `cleanup_otp_tokens`
- **Intervall:** 3600 Sekunden (1 Stunde)
- **Aktion:** `DELETE FROM OtpToken WHERE expiresAt < NOW()`

### Notification Cleanup
- **Task-Name:** `cleanup_notifications`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** `DELETE FROM Notification WHERE createdAt < DATE_SUB(NOW(), INTERVAL 30 DAY)`

### Location Sharing Cleanup
- **Task-Name:** `cleanup_location_sharing`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** Löscht Sessions ohne Location-Updates seit >7 Tagen (mit zugehörigen Locations und Recipients)

### Public Transport Stations Refresh
- **Task-Name:** `pt_stations_refresh`
- **Intervall:** 86400 Sekunden (24 Stunden)
- **Aktion:** Lädt alle DB-Stationen aus `db-stations` GitHub-Repository und aktualisiert die `TravelStop`-Tabelle
- **Dauer:** Je nach Datenmenge mehrere Minuten (1 Request/Sekunde für Rate-Limiting)

### Public Transport Journeys Refresh
- **Task-Name:** `pt_journeys_refresh`
- **Intervall:** 900 Sekunden (15 Minuten)
- **Aktion:** Aktualisiert alle Fahrten, deren `lastCheckedAt` älter als 15 Minuten ist und deren Status nicht `arrived` oder `cancelled` ist

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
