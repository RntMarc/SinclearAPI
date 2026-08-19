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

### Reisedetail (`/travel/trips/{id}`) – **voll implementiert**
- Zeigt Reiseinformationen, Teilnehmer, Unterkünfte und zugehörige Events
- Teilnehmer können hinzugefügt und entfernt werden
- Unterkunftszuweisung pro Teilnehmer änderbar
- Unterkünfte können erstellt, bearbeitet und gelöscht werden
- Dropdown mit allen registrierten Nutzern zum Hinzufügen
- **Forum-Verknüpfung:** Forum auswählen und mit Reise verknüpfen/trennen.
  Beim Verknüpfen werden alle Teilnehmer automatisch in das Forum eingetragen.
- **Abo-Verknüpfung:** Abonnements mit der Reise verknüpfen/trennen.
  Die Abos erscheinen dann auf dem "Zahlungen"-Tab im Client.
- **Gruppenchat:** Gruppenchat erstellen/löschen. Chat-Mitglieder werden automatisch aus Reise-Teilnehmern gespiegelt.

### Eventdetail (`/travel/events/{id}`) – **voll implementiert**
- Zeigt Eventinformationen und Teilnehmer
- Teilnehmer können hinzugefügt und entfernt werden
- Dropdown mit allen registrierten Nutzern zum Hinzufügen

### Moderations-Anfragen (`/moderation-requests`) – **voll implementiert**
- Listet alle Meldungen und Bearbeitungswünsche der Nutzer
- Filter nach Bearbeitungsstatus, Art des Objekts und Art der Anfrage
- Status-Zähler als Schnellzugriff (ungelesen, in Arbeit, …)
- Detailseite pro Anfrage mit Anliegen des Nutzers
- Bearbeitungsstatus und Admin-Kommentar setzen (Rückmeldung an den Nutzer)
- Statistik-Karte auf dem Dashboard (offene Anfragen)

### Abos (`/subscriptions`) – **voll implementiert**
- Listet alle Abonnements mit Preisen und Teilnehmeranzahl
- CRUD für Abonnements
- Teilnehmerverwaltung pro Abo (hinzufügen, entfernen, Bezahlstatus ändern)
- Abos können über die Reisedetail-Seite mit Reisen verknüpft werden

### Benachrichtigungen (`/notifications`) – **voll implementiert**
- Sendet Test-Benachrichtigungen an beliebige Nutzer
- Benachrichtigungen sind exakt identisch mit echten Benachrichtigungen (gleiches Schema, Push-Zustellung)
- Typ-Auswahl für strukturierte Relationsdaten: `forum_reply`, `forum_comment`
- Foren-Posts werden aus der DB geladen und als Vorlage für die Relationsdaten verwendet
- Titel und Text werden von der API aus dem Typ generiert, wenn die Felder leer gelassen werden
- Clients erzeugen den Deep-Link aus Typ und Relationsdaten; Titel und Text kommen von der API
- Live-Vorschau der Benachrichtigung
- Push-Zustellung an alle registrierten Geräte des Empfängers (Web Push + UnifiedPush)

## API-Endpoints

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| GET | `/admin/login` | Login-HTML-Seite |
| POST | `/admin/login/otp/request` | OTP-Code anfordern |
| POST | `/admin/login/otp/verify` | OTP-Code verifizieren + Session starten |
| GET | `/admin/logout` | Session beenden + Redirect zum Login |
| GET | `/admin/` oder `/admin` | Dashboard (geschützt) |
| GET | `/admin/users` | Nutzerverwaltung (geschützt) |
| GET | `/admin/users/json` | Nutzerliste als JSON, optional mit `?q=` Suchparameter (geschützt) |
| GET | `/admin/travel` | Reisen & Events (geschützt) |
| POST | `/admin/travel/trips` | Neue Reise anlegen (geschützt) |
| PUT | `/admin/travel/trips/{id}` | Reise bearbeiten (geschützt) |
| DELETE | `/admin/travel/trips/{id}` | Reise löschen (geschützt) |
| POST | `/admin/travel/events` | Neues Event anlegen (geschützt) |
| PUT | `/admin/travel/events/{id}` | Event bearbeiten (geschützt) |
| DELETE | `/admin/travel/events/{id}` | Event löschen (geschützt) |
| GET | `/admin/travel/trips/{id}` | Reisedetail-Seite mit Teilnehmern, Unterkünften, Events (geschützt) |
| POST | `/admin/travel/trips/{id}/participants` | Teilnehmer zu Reise hinzufügen (geschützt) |
| DELETE | `/admin/travel/trips/{id}/participants/{userId}` | Teilnehmer von Reise entfernen (geschützt) |
| PUT | `/admin/travel/trips/{id}/participants/{userId}/accommodation` | Unterkunftszuweisung ändern (geschützt) |
| PUT | `/admin/travel/trips/{id}/forum` | Forum mit Reise verknüpfen/trennen (geschützt) |
| POST | `/admin/travel/trips/{id}/subscriptions` | Abo mit Reise verknüpfen (geschützt) |
| DELETE | `/admin/travel/trips/{id}/subscriptions/{subscriptionId}` | Abo von Reise trennen (geschützt) |
| POST | `/admin/travel/trips/{id}/accommodations` | Unterkunft erstellen (geschützt) |
| PUT | `/admin/travel/trips/{id}/accommodations/{accId}` | Unterkunft bearbeiten (geschützt) |
| DELETE | `/admin/travel/trips/{id}/accommodations/{accId}` | Unterkunft löschen (geschützt) |
| GET | `/admin/travel/events/{id}` | Eventdetail-Seite mit Teilnehmern (geschützt) |
| POST | `/admin/travel/events/{id}/participants` | Teilnehmer zu Event hinzufügen (geschützt) |
| DELETE | `/admin/travel/events/{id}/participants/{userId}` | Teilnehmer von Event entfernen (geschützt) |
| POST | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise erstellen (geschützt) |
| DELETE | `/admin/travel/trips/{id}/chat` | Gruppenchat für Reise löschen (geschützt) |
| PATCH | `/admin/travel/trips/{id}/chat` | Gruppenchat-Icon für Reise setzen/entfernen (geschützt) |
| POST | `/admin/travel/events/{id}/chat` | Gruppenchat für Event erstellen (geschützt) |
| DELETE | `/admin/travel/events/{id}/chat` | Gruppenchat für Event löschen (geschützt) |
| PATCH | `/admin/travel/events/{id}/chat` | Gruppenchat-Icon für Event setzen/entfernen (geschützt) |
| GET | `/admin/moderation-requests` | Moderations-Anfragen mit Filtern (geschützt) |
| GET | `/admin/moderation-requests/{id}` | Detailseite einer Anfrage (geschützt) |
| POST | `/admin/moderation-requests/{id}/update` | Status + Admin-Kommentar setzen (geschützt) |
| GET | `/admin/notifications` | Benachrichtigungs-Testseite (geschützt) |
| GET | `/admin/notifications/json` | Entitäten als JSON für Dropdowns (geschützt) |
| POST | `/admin/notifications/send` | Test-Benachrichtigung senden (geschützt) |

## Responsive Layout

Das Admin-Dashboard ist vollständig responsiv und passt sich jeder Displaygröße
an (z. B. Smartphones im Hochformat):

- **Desktop (≥ 992px):** Seitliche Navigation ist dauerhaft sichtbar.
- **Mobile (< 992px):** Die Navigation wird zu einem Off-Canvas-Drawer, der über
  den Hamburger-Button in der Topbar geöffnet wird. Ein Klick auf einen
  Navigationslink, das Overlay oder `Escape` schließt ihn wieder.
- Tabellen werden auf kleinen Displays horizontal scrollbar (kein Überlaufen
  des Layouts).
- Mehrspaltige Formulare (`.form-row`) und Detail-Grids (`.detail-grid`) stapeln
  sich auf Mobile vertikal.
- Modals (`.modal`) sind zentriert und bei kleinen Bildschirmen vertikal
  scrollbar.

## Erweiterung

Neue Admin-Seiten können nach dem gleichen Muster ergänzt werden:

1. Methode in `AdminController.php` hinzufügen
2. Template in `templates/admin/` anlegen
3. Route in `config/routes.php` registrieren
4. Seitenlink in `templates/admin/layout.php` ergänzen
