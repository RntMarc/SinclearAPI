# Admin-Dashboard

## Übersicht

Das Admin-Dashboard ist ein einfaches Web-Interface unter `/api/v2/admin/*`,
das ausschließlich für Administratoren zugänglich ist. Es dient zu Test- und
Verwaltungszwecken und verwendet die gleiche JWT-Authentifizierung wie die
normalen API-Clients.

## Authentifizierung

1. Admin ruft `/api/v2/admin/login` auf
2. E-Mail-Adresse eingeben → `POST /admin/login/otp/request` sendet OTP-Code
3. 6-stelligen Code eingeben → `POST /admin/login/otp/verify` gibt Tokens zurück
4. Access-Token wird im localStorage gespeichert und bei jedem Request als
   `Authorization: Bearer <token>` mitgesendet
5. Bei Ablauf oder Abmeldung: Token löschen → zurück zum Login

Der Login-Endpunkt prüft, ob der Nutzer `isAdmin = true` in der Datenbank hat.
Nicht-Admins erhalten einen 403-Fehler.

## Seiten

### Dashboard (`/`)
- Zeigt Nutzer- und Reise-Anzahl an
- Links zu den drei Hauptbereichen

### Nutzerverwaltung (`/users`) – **Platzhalter**
- Listet alle registrierten Nutzer in einer Tabelle
- Bearbeiten-Buttons sind deaktiviert mit "Coming soon"
- Geplante Funktionen: Bearbeiten, Timeout/Ban, Einladungen

### Reisen & Events (`/travel`) – **Platzhalter**
- Listet alle Reisen und Events in Tabellen
- Bearbeiten-Buttons sind deaktiviert mit "Coming soon"
- Geplante Funktionen: Erstellen, Bearbeiten, Löschen

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
| POST | `/admin/login/otp/verify` | OTP-Code verifizieren + Tokens |
| GET | `/admin/` oder `/admin` | Dashboard (geschützt) |
| GET | `/admin/users` | Nutzerverwaltung (geschützt) |
| GET | `/admin/users/json` | Nutzerliste als JSON (geschützt) |
| GET | `/admin/travel` | Reisen & Events (geschützt) |
| GET | `/admin/notifications` | Benachrichtigungen senden (geschützt) |
| POST | `/admin/notifications/send` | Notification versenden (geschützt) |

## Erweiterung

Neue Admin-Seiten können nach dem gleichen Muster ergänzt werden:

1. Methode in `AdminController.php` hinzufügen
2. Template in `templates/admin/` anlegen
3. Route in `config/routes.php` registrieren
4. Seitenlink in `templates/admin/layout.php` ergänzen
