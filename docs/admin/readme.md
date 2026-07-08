# Admin-Dashboard

## Übersicht

Das Admin-Dashboard ist ein einfaches Web-Interface unter `/api/v2/admin/*`,
das ausschließlich für Administratoren zugänglich ist. Es dient zu Test- und
Verwaltungszwecken und verwendet PHP-Sessions für die Authentifizierung.

## Authentifizierung

1. Admin ruft `/api/v2/admin/login` auf
2. E-Mail-Adresse eingeben → `POST /admin/login/otp/request` sendet OTP-Code
3. 6-stelligen Code eingeben → `POST /admin/login/otp/verify` startet Session
4. Session-Cookie wird vom Browser automatisch bei Folge-Requests mitgesendet
5. Bei Abmeldung: `GET /admin/logout` zerstört Session → zurück zum Login

Der Login-Endpunkt prüft, ob der Nutzer `isAdmin = true` in der Datenbank hat.
Nicht-Admins erhalten einen 403-Fehler.

### Session-Verwaltung

- Die `AdminMiddleware` prüft bei jedem Seitenaufruf einer geschützten
  Admin-Seite, ob eine gültige Session mit `admin_id` und `admin_email`
  existiert. Bei fehlender Session wird der Browser zur Login-Seite
  weitergeleitet (302 Redirect).
- AJAX-Requests (z.B. Benachrichtigungen senden) senden das Session-Cookie
  automatisch mit (`credentials: 'same-origin'`).
- Die Login-Seite prüft serverseitig, ob bereits eine Session existiert –
  wenn ja, wird direkt zum Dashboard weitergeleitet.

## Seiten

### Dashboard (`/`)
- Zeigt Nutzer- und Reise-Anzahl an
- Links zu den drei Hauptbereichen

### Nutzerverwaltung (`/users`) – **Platzhalter**
- Listet alle registrierten Nutzer in einer Tabelle
- Bearbeiten-Buttons sind deaktiviert mit "Coming soon"
- Geplante Funktionen: Bearbeiten, Timeout/Ban, Einladungen

### Reisen & Events (`/travel`) – **voll implementiert**
- Listet alle Reisen und Events in Tabellen
- Reisen und Events können erstellt, bearbeitet und gelöscht werden
- Standalone-Events (ohne Reise-Bezug) können angelegt werden
- Ticket-Informationen, Orts- und Kontaktdaten verwaltbar
- OpenStreetMap-ID, Koordinaten und Adresse pro Event

### Benachrichtigungen (`/notifications`) – **voll implementiert**
- Vordefinierte Vorlagen: System-Update, Neue Funktion, Wartungshinweis,
  Willkommensnachricht, Test Ping
- Benutzerdefinierte Benachrichtigung mit eigenem Titel/Text
- Empfänger-Auswahl über Dropdown
- Deep-Link-Auswahl zur Zielseite in der App

## Notification-Codes (Admin)

| Code | Payload | Beschreibung |
|------|---------|-------------|
| `admin.system_update` | `{ "deepLink": "..." }` | System-Update-Ankündigung |
| `admin.new_feature` | `{ "deepLink": "..." }` | Neue Funktion verfügbar |
| `admin.maintenance` | `{ "deepLink": "..." }` | Wartungshinweis |
| `admin.welcome` | `{ "deepLink": "..." }` | Willkommensnachricht |
| `admin.test` | `{ "deepLink": "..." }` | Test-Ping |
| `admin.custom` | `{ "deepLink": "...", "title": "...", "body": "..." }` | Freie Nachricht |

## API-Endpoints

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| GET | `/admin/login` | Login-HTML-Seite |
| POST | `/admin/login/otp/request` | OTP-Code anfordern |
| POST | `/admin/login/otp/verify` | OTP-Code verifizieren + Session starten |
| GET | `/admin/logout` | Session beenden + Redirect zum Login |
| GET | `/admin/` oder `/admin` | Dashboard (geschützt) |
| GET | `/admin/users` | Nutzerverwaltung (geschützt) |
| GET | `/admin/users/json` | Nutzerliste als JSON (geschützt) |
| GET | `/admin/travel` | Reisen & Events (geschützt) |
| POST | `/admin/travel/trips` | Neue Reise anlegen (geschützt) |
| PUT | `/admin/travel/trips/{id}` | Reise bearbeiten (geschützt) |
| DELETE | `/admin/travel/trips/{id}` | Reise löschen (geschützt) |
| POST | `/admin/travel/events` | Neues Event anlegen (geschützt) |
| PUT | `/admin/travel/events/{id}` | Event bearbeiten (geschützt) |
| DELETE | `/admin/travel/events/{id}` | Event löschen (geschützt) |
| GET | `/admin/notifications` | Benachrichtigungen senden (geschützt) |
| POST | `/admin/notifications/send` | Notification versenden (geschützt) |

## Erweiterung

Neue Admin-Seiten können nach dem gleichen Muster ergänzt werden:

1. Methode in `AdminController.php` hinzufügen
2. Template in `templates/admin/` anlegen
3. Route in `config/routes.php` registrieren
4. Seitenlink in `templates/admin/layout.php` ergänzen
