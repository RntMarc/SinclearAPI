# CalDAV & CardDAV (read-only)

Die API stellt eine **lesende** CalDAV- und CardDAV-Schnittstelle bereit,
damit Kalender-Apps (z.B. DAVx5, Apple Kalender, Thunderbird) den Beyond-Kalender
und die Beyond-Kontakte synchronisieren können.

> **Wichtig:** Die Schnittstelle ist **read-only**. Neue Termine können nicht
> über CalDAV angelegt werden; Kontakte (Beyond-Nutzer) sind grundsätzlich
> nicht über CardDAV anlegbar, da sie der `User`-Tabelle entsprechen.

## Basis-URL & Discovery

Die API liegt auf dem Webserver unter dem Pfad `/api/` (die Root-Domain
`sinclear.de` wird von der Web-App belegt). Der DAV-Endpunkt ist daher
unter dem vollen Pfad `/api/dav/` erreichbar:

| Zweck | URL |
|-------|-----|
| DAV-Basis-URL | `https://sinclear.de/api/dav/` |
| Beyond Kalender | `https://sinclear.de/api/dav/calendars/{userId}/calendar/` |
| Reisen & Fahrten | `https://sinclear.de/api/dav/calendars/{userId}/travel/` |
| Geburtstage | `https://sinclear.de/api/dav/calendars/{userId}/birthdays/` |
| Adressbuch | `https://sinclear.de/api/dav/addressbooks/{userId}/contacts/` |

RFC-6764-Discovery (`.well-known/caldav` bzw. `.well-known/carddav`) ist
nur innerhalb des API-Pfads erreichbar, da die Root-Domain von der Web-App
bedient wird:

- `https://sinclear.de/api/.well-known/caldav` → 301 Redirect auf `/api/dav/`
- `https://sinclear.de/api/.well-known/carddav` → 301 Redirect auf `/api/dav/`

Clients, die Discovery automatisch auf der Root-Domain versuchen (z.B.
DAVx5 mit `https://sinclear.de/`), finden den Dienst dadurch nicht – dort
ist die Basis-URL **manuell** einzutragen.

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

Je Nutzer werden **drei Kalender** ausgeliefert, die einzeln abonniert
werden können (z.B. in DAVx5):

| Kalender | URI | Farbe | Inhalt |
|----------|-----|-------|--------|
| Beyond Kalender | `calendar` | Indigo `#6366f1` | Normale Kalender-Events |
| Reisen & Fahrten | `travel` | Amber `#f59e0b` | Reisen, Reise-Events und ÖPNV-Fahrten |
| Geburtstage | `birthdays` | Pink `#ec4899` | Geburtstage sichtbarer Nutzer |

Die Daten stammen aus demselben Service wie `GET /calendar/all`
(`CalendarFeedService`), daher gelten identische Sichtbarkeitsregeln:

- **Beyond Kalender** (`calendar_event`): Ersteller, Teilnehmer,
  Sichtbarkeit `1` (öffentlich), `2` (enge Freunde).
- **Reisen & Fahrten** (`travel_event`, `trip`, `pt_journey`): Zugriff
  über `EventRelation`/`TravelRelation` (Reisen) bzw. `PtParticipant`
  (ÖPNV-Fahrten).
- **Geburtstage** (`birthday`): `birthdayVisibility` (0 = niemand,
  1 = alle, 2 = enge Freunde).

**Allgemein:**
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
| `UID` | `{feedItemId}@sinclear.de` |
| `DTSTART` / `DTEND` | `startTime` / `endTime` (UTC, Format `…Z`) |
| `DTSTAMP` | `updatedAt` bzw. aktueller Zeitpunkt |
| `SUMMARY` / `DESCRIPTION` | `title` / `description` (bzw. Leg-Details bei ÖPNV) |
| `CLASS` | `visibility`: 0 → `PRIVATE`, 1 → `PUBLIC`, 2 → `CONFIDENTIAL` (nur calendar_event) |
| `ORGANIZER` / `ATTENDEE` | `mailto:{userId}@sinclear.de` (keine echten E-Mail-Adressen) |
| `RRULE` | `FREQ=YEARLY` (nur Geburtstage) |
| `TRANSP` | `TRANSPARENT` (Reisen, Geburtstage – nicht als „besetzt" markieren) |

### Kalender-URLs

| Kalender | Vollständige URL |
|----------|-----------------|
| Beyond Kalender | `https://sinclear.de/api/dav/calendars/{userId}/calendar/` |
| Reisen & Fahrten | `https://sinclear.de/api/dav/calendars/{userId}/travel/` |
| Geburtstage | `https://sinclear.de/api/dav/calendars/{userId}/birthdays/` |

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
2. Basis-URL: `https://sinclear.de/api/dav/`
3. Benutzername: eigene E-Mail-Adresse, Passwort: DAV-Token
4. DAVx5 erkennt Kalender (CalDAV) und Kontakte (CardDAV) automatisch

### Apple (iOS / macOS)

Da die Discovery-URLs auf der Root-Domain nicht erreichbar sind (dort
läuft die Web-App), muss der Account-Pfad manuell gesetzt werden:

1. Einstellungen → Kalender/Kontakte → Account hinzufügen → „Sonstige"
   → „CalDAV- bzw. CardDAV-Account hinzufügen"
2. Server: `sinclear.de`, Benutzername: E-Mail-Adresse, Passwort: DAV-Token
3. Unter „Erweitert" als Account-URL `https://sinclear.de/api/dav/principals/{userId}/`
   eintragen (bzw. bei CardDAV direkt `https://sinclear.de/api/dav/addressbooks/{userId}/contacts/`
   verwenden)

### Thunderbird

1. „Neuer Kalender" → „Im Netzwerk" → CalDAV
2. URL: `https://sinclear.de/api/dav/calendars/{userId}/calendar/` (bzw. `/travel/` oder `/birthdays/`)
3. Benutzername: E-Mail-Adresse, Passwort: DAV-Token
4. Für jeden Kalender wiederholen (Reisen & Fahrten, Geburtstage)

## Technische Details

- Implementierung basiert auf [sabre/dav 4.x](https://sabre.io/dav/)
  (gleiche Basis wie Nextcloud).
- Eigener Front-Controller: `public/dav.php` (außerhalb der REST-API unter
  `/api/v2`), erreichbar unter `/api/dav/`.
- Datenquelle: `CalendarFeedService` (identisch zu `GET /calendar/all`),
  gefiltert nach `type` je Kalender.
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
