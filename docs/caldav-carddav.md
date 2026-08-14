# CalDAV & CardDAV (read-only)

Die API stellt eine **lesende** CalDAV- und CardDAV-Schnittstelle bereit,
damit Kalender-Apps (z.B. DAVx5, Apple Kalender, Thunderbird) den Beyond-Kalender
und die Beyond-Kontakte synchronisieren können.

> **Wichtig:** Die Schnittstelle ist **read-only**. Neue Termine können nicht
> über CalDAV angelegt werden; Kontakte (Beyond-Nutzer) sind grundsätzlich
> nicht über CardDAV anlegbar, da sie der `User`-Tabelle entsprechen.

## Basis-URL & Discovery

| Zweck | URL |
|-------|-----|
| DAV-Basis-URL | `https://<api-host>/dav/` |
| Kalender | `https://<api-host>/dav/calendars/{userId}/calendar/` |
| Adressbuch | `https://<api-host>/dav/addressbooks/{userId}/contacts/` |

Clients, die RFC 6764 Discovery unterstützen, finden die Basis-URL automatisch:

- `https://<api-host>/.well-known/caldav` → 301 Redirect auf `/dav/`
- `https://<api-host>/.well-known/carddav` → 301 Redirect auf `/dav/`

## Authentifizierung (DAV-Tokens)

Die API kennt keine Passwörter (Login via E-Mail-OTP oder Discord). Für
DAV-Clients werden deshalb **app-spezifische Tokens** verwendet
(vergleichbar mit Google App-Passwörtern):

1. In der App unter den Einstellungen ein DAV-Token erstellen.
2. Das Token wird **einmalig** angezeigt und kann danach nicht mehr
   ausgelesen werden.
3. Im DAV-Client als Zugangsdaten eintragen:
   - **Benutzername:** die eigene E-Mail-Adresse
   - **Passwort:** das DAV-Token

| Eigenschaft | Wert |
|-------------|------|
| Gültigkeit | 365 Tage |
| Max. aktive Tokens pro Nutzer | 5 |
| Speicherung | Nur als SHA-256-Hash in der Tabelle `DavToken` |

### Token-Verwaltung (API-Endpunkte, JWT-Auth)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/user/me/dav-tokens` | Token erstellen (`{ "label": "DAVx5 – Pixel 8" }`), Token wird einmalig im Klartext zurückgegeben |
| `GET` | `/user/me/dav-tokens` | Tokens auflisten (paginierte, ohne Klartext) |
| `DELETE` | `/user/me/dav-tokens/{id}` | Token widerrufen |

## Ungültiger oder abgelaufener Token

Meldet sich ein Client mit einem ungültigen, abgelaufenen oder fehlenden
Token an, erhält er statt einer 401-Fehlermeldung einen **Hinweis-Eintrag**:

- **CalDAV:** Ein virtueller Kalender mit genau einem Event
  „Abgelaufener oder ungültiger Token" – platziert am Abfragetag von
  12:00 bis 13:00 UTC. Beschreibung: *„Der von dir für den Login verwendete
  DAV-Token ist entweder abgelaufen oder ungültig. Bitte fordere einen neuen
  Token in den Einstellungen von Sinclear Beyond an oder wende dich bei
  Problemen an einen Administrator."*
- **CardDAV:** Ein virtuelles Adressbuch mit genau einem Kontakt mit
  demselben Namen und demselben Hinweistext als Notiz.

Das Hinweis-Event wird bei jedem Abruf neu für den aktuellen Tag berechnet
und nicht gespeichert – es entsteht nie mehr als ein Eintrag.

## CalDAV – Kalender

- Je Nutzer wird genau ein Kalender „Beyond Kalender" ausgeliefert.
- Enthalten sind alle `CalendarEvent`-Einträge, die der Nutzer sehen darf
  (Ersteller, Teilnehmer, Sichtbarkeit `1` öffentlich, `2` enge Freunde).
- Ausgeliefertes Zeitfenster: von 1 Jahr in der Vergangenheit bis 2 Jahre
  in der Zukunft.
- Unterstützte REPORTs: `calendar-query` und `calendar-multiget`
  (`sync-collection` wird nicht angeboten; Clients fallen automatisch auf
  `calendar-query` zurück).
- Schreibzugriffe (`PUT`/`DELETE`/`MKCALENDAR`) werden mit `403 Forbidden`
  beantwortet.

### ICS-Abbildung

| ICS-Eigenschaft | Quelle |
|-----------------|--------|
| `UID` | `{eventId}@sinclear.de` |
| `DTSTART` / `DTEND` | `startTime` / `endTime` (UTC, Format `…Z`) |
| `DTSTAMP` | `updatedAt` |
| `SUMMARY` / `DESCRIPTION` | `title` / `description` |
| `CLASS` | `visibility`: 0 → `PRIVATE`, 1 → `PUBLIC`, 2 → `CONFIDENTIAL` |
| `ORGANIZER` / `ATTENDEE` | `mailto:{userId}@sinclear.de` (keine echten E-Mail-Adressen) |

## CardDAV – Adressbuch

- Je Nutzer wird genau ein Adressbuch „Beyond Kontakte" ausgeliefert.
- Enthalten sind **alle Beyond-Nutzer**, gefiltert nach deren
  Sichtbarkeitseinstellungen (0 = nur ich, 1 = alle, 2 = enge Freunde):
  - `FN` / `N`: immer der Anzeigename
  - `EMAIL`: nur wenn `emailVisibility` die Sicht zulässt
  - `BDAY`: nur wenn `birthdayVisibility` die Sicht zulässt
  - `UID`: `{userId}@sinclear.de`
- Die eigenen Daten sind vollständig sichtbar (auch bei Sichtbarkeit 0).
- Schreibzugriffe werden mit `403 Forbidden` beantwortet.

## Zeitzonen (UTC-only)

Die API arbeitet ausschließlich in UTC. Alle Datums-/Zeitangaben im ICS
werden mit `Z`-Suffix (UTC) serialisiert. Die Umrechnung in die lokale
Zeitzone übernimmt der Client.

## Client-Einrichtung (Beispiele)

### DAVx5 (Android)

1. „Konto hinzufügen" → „Anmeldung mit URL und Benutzername"
2. Basis-URL: `https://<api-host>/dav/`
3. Benutzername: eigene E-Mail-Adresse, Passwort: DAV-Token
4. DAVx5 erkennt Kalender (CalDAV) und Kontakte (CardDAV) automatisch

### Apple (iOS / macOS)

1. Einstellungen → Kalender/Kontakte → Account hinzufügen → „Sonstige"
   → „CalDAV- bzw. CardDAV-Account hinzufügen"
2. Server: `<api-host>`, Benutzername: E-Mail-Adresse, Passwort: DAV-Token
3. Die Discovery-URLs (`/.well-known/caldav` bzw. `/.well-known/carddav`)
   werden von Apple automatisch verwendet

### Thunderbird

1. „Neuer Kalender" → „Im Netzwerk" → CalDAV
2. URL: `https://<api-host>/dav/calendars/{userId}/calendar/`
3. Benutzername: E-Mail-Adresse, Passwort: DAV-Token

## Technische Details

- Implementierung basiert auf [sabre/dav 4.x](https://sabre.io/dav/)
  (gleiche Basis wie Nextcloud).
- Eigener Front-Controller: `public/dav.php` (außerhalb der REST-API unter
  `/api/v2`).
- Die Tabelle `DavToken` wird über die Migration
  `database/migrations/create_dav_tokens_table.sql` angelegt.
- Ohne gültiges Token erhält ein Client ausschließlich den virtuellen
  Hinweis-Kalender/-Kontakt – keine Nutzerdaten.

## Grenzen / Ausblick

- **Kein Schreibzugriff:** PUT/POST/DELETE werden abgelehnt. Ein schreibender
  CalDAV-Modus (Anlegen/Ändern von `CalendarEvent` inkl. Benachrichtigungen)
  ist als spätere Erweiterung möglich; CardDAV bleibt dauerhaft read-only.
- **Kein sync-collection:** Clients synchronisieren über `calendar-query`
  bzw. `addressbook-query` mit Zeitbereich.
- **Kein PHOTO in vCards:** Profilbilder werden vorerst nicht in die vCards
  eingebettet (Payload-Größe).
