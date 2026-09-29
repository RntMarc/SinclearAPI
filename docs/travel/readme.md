# Reisen (Travel)

Die Travel-Funktion erlaubt Nutzern das Verwalten und Abrufen von Reisen,
zugehörigen Events und Unterkünften. Jeder Nutzer sieht nur die Reisen,
bei denen er über die `TravelRelation`-Tabelle als Teilnehmer eingetragen ist.

> **Hinweis zu Zeitangaben:** Die API ist zeitzonen-bewusst. Jede Reise und jedes Event trägt eine IANA-Zeitzone (`timezone`, z. B. `Europe/Berlin`).
> 
> - **Getaktet (`allDay: false`):** `startAt`/`endAt` sind RFC 3339 **mit Offset** (`2026-09-26T14:30:00+02:00` oder `...Z`); intern wird der UTC-Instant gespeichert.
> - **Ganztägig (`allDay: true`, Standard bei Reisen):** `startDate`/`endDate` sind zivile Tage (`YYYY-MM-DD`, inklusives Ende), ohne Uhrzeitfelder.
> - **Ausgabe:** Getaktete Einträge werden im Offset ihrer `timezone` ausgegeben, ganztägige als `startDate`/`endDate`.
> - Clients senden Wandzeiten mit Offset und der gemeinten IANA-Zeitzone; die eigene Zeitzone stammt aus `UserPreferences.timezone` bzw. der Gerätezeitzone.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `TravelTrip` | Reisedaten (Name, Beschreibung, `allDay`, `timezone`, `startAt`/`endAt` bzw. `startDate`/`endDate`, `state` = `planning`/`active`/`cancelled`) |
| `TravelEvent` | Ereignisse (Reise-Events + Standalone-Events via `trip IS NULL`, citySlug, `allDay`, `timezone`, `startAt`/`endAt` bzw. `startDate`/`endDate`) |
| `TravelEventTicket` | Tickets für Reisen, Events oder persönliche Nutzer-Tickets |
| `TravelAccommodation` | Globaler Katalog wiederverwendbarer Unterkünfte (Hotels, Ferienwohnungen, etc., citySlug, `createdBy`) |
| `TravelAccommodationTrip` | n:m-Verknüpfung einer Katalog-Unterkunft mit Reisen (`tripid`, `accommodationId`, optional `pricePerPersonPerNight`/`currency`) |
| `TravelRelation` | Verknüpfung von Nutzern mit Reisen und Unterkünften (inkl. `role` = `leader`/`participant` und `accommodation` = zugewiesene Unterkunft) |
| `EventRelation` | Teilnehmer an Events (sowohl Reise- als auch Standalone; inkl. `role` bei Standalone-Events) |
| `TravelChat` | Verknüpfung von Gruppenchats mit Reisen oder Events |
| `TravelPlanMember` | Planungsteilnehmer (getrennt von `TravelRelation`, `status` = `invited`/`accepted`/`declined`/`inactive`) |
| `TravelPlanTopic` | Phasenstatus je Planungsreise (`topic` = `participants`/`travel`/`program`, `status` = `pending`/`in_progress`/`completed`/`skipped`) |
| `TravelPlanDateOption` | Terminoptionen einer Planungsreise (zeitbewusst; `isFinal` = von der Leitung festgelegt) |
| `TravelPlanDateResponse` | Verfügbarkeits-Rückmeldung je Terminoption und Mitglied (`yes`/`maybe`/`no`) |
| `TravelPlanTransport` | Transportpräferenz je Mitglied und Richtung (`direction` = `outbound`/`return`, Mitfahrangebot `offersRide`/`availableSeats`) |
| `TravelPlanAccommodationOption` | Unterkunftsoptionen inkl. Preis pro Person und Nacht + Währung (`isSelected` = gewählt) |
| `TravelPlanEvent` | Tagesprogramm-Vorschläge ("Planungs-Events"; `isConfirmed`, `confirmedEventId` nach Aktivierung) |
| `TravelPlanEventInterest` | Teilnahmeinteresse an Tagesprogramm-Vorschlägen (`yes`/`maybe`/`no`) |

## Banner-Bild (TravelEvent.image)

Jedes `TravelEvent` kann ein Banner-Bild im `image`-Feld speichern.

**Eigenschaften:**
- **Format:** Base64-kodierter String (JPEG, PNG oder WebP)
- **Seitenverhältnis:** 3.5:1 (Breite:Höhe) – wird beim Upload per Cropper-UI erzwungen
- **Max. Dateigröße:** 500 KB (decoded)
- **Max. Abmessungen:** 2000px Breite
- **Konvertierung:** Nicht-JPEG-Bilder werden automatisch in JPEG (Quality 0.85) konvertiert

**Upload:**
- Nur über Admin-Dashboard (Reisen & Events → Event erstellen/bearbeiten → Banner-Bild)
- Datei auswählen → Cropper-Modal öffnet → Zuschnitt auf 3.5:1 → Übernehmen
- Das Bild wird als Base64-String im JSON-Body an die API gesendet

**Speicherung:**
- Das API speichert den Base64-String direkt in der Datenbank (`TravelEvent.image`)
- Kein Dateisystem-Upload, keine CDN-Verteilung

**Client-Rendering:**
```html
<img src="data:image/jpeg;base64,{{image}}" style="aspect-ratio:3.5/1;object-fit:cover;">
```

## Autorisierungs-Logik

Alle Endpunkte benötigen einen gültigen JWT (Bearer Token).

| Endpunkt | Zugriffsprüfung |
|----------|----------------|
| `GET /trips` | Nur Reisen, bei denen der Nutzer in `TravelRelation` steht |
| `GET /trips/{id}` | Nutzer muss Teilnehmer der Reise sein → sonst `403` |
| `GET /trips/{id}/events` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/events/{eventId}` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/tickets` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/accommodations` | Nutzer muss Teilnehmer der Reise sein |
| `POST /trips/{id}/accommodations` | Nutzer muss Teilnehmer der Reise sein (Reiseleiter und Mitreisende) |
| `GET /trips/{id}/accommodations/{accommodationId}` | Nutzer muss Teilnehmer der Reise sein |
| `PATCH /trips/{id}/accommodations/{accommodationId}` | Reiseleiter der Reise, Ersteller der Katalog-Unterkunft oder Admin |
| `DELETE /trips/{id}/accommodations/{accommodationId}` | Nur Reiseleiter (löst die Verknüpfung, löscht nicht den Katalog) |
| `GET /trips/accommodations` | Authentifizierter Nutzer (globaler Katalog) |
| `DELETE /trips/accommodations/{accommodationId}` | Nur Ersteller der Unterkunft oder Admin (endgültiges Löschen) |
| `GET /trips/{id}/participants` | Nutzer muss Teilnehmer der Reise sein |
| `PUT /trips/{id}/participants/{userId}/accommodation` | Reiseleiter für alle Teilnehmer; Mitreisende nur für sich selbst |
| `POST /trips/{id}/events/{eventId}/participants` | Nur Reiseleiter der zugehörigen Reise |
| `DELETE /trips/{id}/events/{eventId}/participants/{userId}` | Nur Reiseleiter der zugehörigen Reise |
| `GET /trips/standaloneevents` | Nur Events, bei denen Nutzer in `EventRelation` steht |
| `GET /trips/standaloneevents/{eventId}` | Nutzer muss in `EventRelation` sein → sonst `404` |
| `GET /trips/events/{eventId}` | Nutzer muss Teilnehmer des Events oder der zugehörigen Reise sein |
| `GET /trips/events/{eventId}/tickets` | Nutzer muss Teilnehmer des Events oder der zugehörigen Reise sein |
| `GET /trips/tickets/user` | Authentifizierter Nutzer (nur eigene Tickets) |
| `POST /trips/tickets/user` | Authentifizierter Nutzer (erstellt eigenes Ticket) |
| `PUT /trips/tickets/user/{ticketId}` | Nur Besitzer des Tickets |
| `DELETE /trips/tickets/user/{ticketId}` | Nur Besitzer des Tickets |

Sobald die Trip-Teilnahme bestätigt ist, werden alle zugehörigen Events
und Unterkünfte uneingeschränkt ausgegeben (nicht nur die eigenen).

## Rollen & Bearbeitungsrechte

Reisen und Events unterscheiden zwei Rollen pro Teilnehmer-Relation:

| Rolle | Bedeutung |
|-------|-----------|
| `leader` | Reiseleiter (bei Reisen) bzw. Veranstalter (bei Standalone-Events). Darf das Objekt und seine Unterressourcen bearbeiten/löschen. |
| `participant` | Einfacher Mitreisender/Teilnehmer. Nur Leserechte. |

**Regeln:**

- Jeder Nutzer kann über `POST /trips` eine Reise erstellen. Der Ersteller
  wird automatisch als `leader` in `TravelRelation` (`role='leader'`)
  eingetragen.
- Nur `leader` einer Reise dürfen die Reise, ihre Reise-Events und ihre
  Unterkünfte bearbeiten/löschen sowie Teilnehmer und Rollen verwalten.
- **Unterkünfte:** Jeder Reiseteilnehmer (auch Mitreisende) darf Unterkünfte
  anlegen bzw. vorhandene Katalog-Unterkünfte mit der Reise verknüpfen. Einem
  anderen Teilnehmer eine Unterkunft zuweisen darf nur ein Reiseleiter; sich
  selbst darf jeder Teilnehmer eine Unterkunft zuweisen. Katalog-Details
  bearbeiten/endgültig löschen darf der jeweilige Ersteller (oder Admin).
- **Reise-Event-Teilnehmer:** Reise-Events besitzen eine eigene
  Teilnehmerliste (`EventRelation`). Reiseleiter können jederzeit Nutzer über
  `POST`/`DELETE /trips/{id}/events/{eventId}/participants[/{userId}]`
  hinzufügen oder entfernen. Die Teilnehmer werden nicht automatisch aus der
  Reise übernommen (kein „automatisch dabei“). Rollen werden bei Reise-Events
  nicht pro Event vergeben – es gelten die Reise-Rollen.
- Reiseleiter können weitere Teilnehmer über
  `PUT /trips/{id}/participants/{userId}/role` zu `leader` ernennen oder
  wieder degradieren.
- **Letzter Reiseleiter:** Der letzte verbleibende `leader` kann weder entfernt
  noch degradiert werden (`409 last_leader`).
- **Reise-Events erben** die Bearbeitungsrechte von der zugehörigen Reise.
- **Standalone-Events** (`trip IS NULL`) besitzen eigene Rollen in
  `EventRelation`: Der Ersteller ist `leader` und kann weitere Teilnehmer als
  Veranstalter (`leader`) ernennen.
- Normale Events müssen zwingend einer Reise zugeordnet sein; ein Event ohne
  `trip` ist immer ein Standalone-Event.
- **Konversion:** `PATCH /trips/standaloneevents/{eventId}` mit `trip` hängt ein
  Standalone-Event an eine Reise an; `PATCH /trips/{id}/events/{eventId}` mit
  `trip: null` löst ein Reise-Event zu einem Standalone-Event. Erforderlich sind
  Leader-Rechte an Quelle **und** Ziel. Beim Konvertieren werden alle
  EventRelation-Rollen auf `participant` zurückgesetzt; beim Lösen wird der
  handelnde Leader als Veranstalter eingetragen.

## Event-Teilnehmer (EventRelation)

Jedes `TravelEvent` kann über die `EventRelation`-Tabelle Teilnehmer haben.
Die Teilnehmer werden als `participants`-Array im Response mitgeliefert:

```json
{
  "data": {
    "ID": "...",
    "name": "Konzert Berlin",
    "image": "base64-kodiertes Banner-Bild (3.5:1) oder null",
    "participants": [
      { "id": "...", "displayName": "Max", "image": null }
    ]
  }
}
```

## Wiederverwendbare Unterkünfte (Katalog + Zuordnung)

Unterkünfte sind **global und wiederverwendbar**: Sie werden einmal angelegt
und können anschließend in beliebig vielen Reisen verwendet werden. Dafür
gilt ein zweistufiges Modell:

* **Katalog:** `TravelAccommodation` ist der globale Bestand. Über
  `GET /trips/accommodations` (optional `?q=<name>`) listet jeder
  authentifizierte Nutzer alle Unterkünfte. `createdBy` hält den Ersteller
  fest.
* **Reise-Verknüpfung:** `TravelAccommodationTrip` verknüpft eine
  Katalog-Unterkunft mit einer Reise. `POST /trips/{id}/accommodations`
  legt entweder eine neue Unterkunft an (Feld `name` …) oder verknüpft eine
  vorhandene (`{ "accommodationId": "..." }`). `DELETE
  /trips/{id}/accommodations/{accommodationId}` löst die Verknüpfung wieder
  (Katalogeintrag bleibt erhalten); `DELETE
  /trips/accommodations/{accommodationId}` entfernt ihn endgültig (nur
  Ersteller/Admin).
* **Teilnehmer-Zuordnung:** `TravelRelation.accommodation` ordnet einem
  Teilnehmer innerhalb einer Reise eine Unterkunft zu. Die Zuordnung erfolgt
  über `PUT /trips/{id}/participants/{userId}/accommodation` mit
  `{ "accommodation": "<id>" | null }`. Wird eine noch nicht verknüpfte
  Katalog-Unterkunft zugewiesen, wird sie automatisch mit der Reise
  verknüpft.

Bestandsunterkünfte mit gesetzter `TravelAccommodation.tripId` bleiben
sichtbar; neu angelegte Unterkünfte werden ausschließlich über
`TravelAccommodationTrip` verknüpft.

Die zugeordneten Nutzer werden als `users`-Array im Response mitgeliefert:

```json
{
  "data": {
    "ID": "...",
    "name": "Hotel Sonnenschein",
    "createdBy": "...",
    "users": [
      { "id": "...", "displayName": "Max", "image": null }
    ]
  }
}
```

## Travel-Gruppenchats

Reisen und Standalone-Events können admin-seitig mit einem Gruppenchat versehen werden.
Die Chat-Mitglieder werden automatisch aus den Teilnehmern der Reise/Events gespiegelt.

**Datenbank:** `TravelChat` speichert die Zuordnung (Reise oder Event) zur `ChatConversation`.

**Verhalten:**
- Admin erstellt Chat über `POST /admin/travel/trips/{id}/chat` oder `POST /admin/travel/events/{id}/chat`
- `ChatConversation` wird mit `type=group` und `name=Reise-/Event-Name` angelegt
- `ChatParticipant` wird aus `TravelRelation`/`EventRelation` gespiegelt
- Bei Hinzufügen/Entfernen von Teilnehmern wird der Chat automatisch synchronisiert
- `conversationId` ist in den Trip/Event-Responses enthalten
- Chat-Icon kann über `PATCH /admin/travel/trips/{id}/chat` oder `PATCH /admin/travel/events/{id}/chat` gesetzt werden
- Chat wird bei Löschung der Reise/Events automatisch gelöscht (FK-Cascade)

**Client-Zugang:** Über `GET /chat/conversations` erscheinen Gruppenchats alongside 1:1-Chats in der Übersicht.

## Tickets (TravelEventTicket)

Die `TravelEventTicket`-Tabelle erlaubt das Hinterlegen von Tickets für Events,
Reisen oder als persönliches Ticket. Es gibt drei Typen:

| Typ | Verwaltung | Beschreibung | Scope |
|-----|------------|--------------|-------|
| `event` | Admin | Tickets für ein bestimmtes Event (z.B. Gruppeneintrittskarte), die für die gesamte Gruppe gelten. | Ein Event-Ticket kann nur zu einem Event hinzugefügt werden und ist für alle Teilnehmer am Event gültig (z.B. Gruppeneintrittskarte). |
| `trip` | Admin | Tickets für eine gesamte Reise (z.B. Gruppenticket), die für die gesamte Gruppe gelten. | Ein Trip-Ticket kann nur zu einer Reise hinzugefügt werden und ist für alle Mitreisenden Nutzer gültig (z.B. Gruppen-Fahrkarte, gemeinsames Hotelzimmer der Gruppe). |
| `user` | Self-Service | Persönliche Tickets des Nutzers, die nur für den Nutzer gelten. | Ein User-Ticket kann sowohl zu einer Reise als auch zu einem Event hinzugefügt werden (aber nicht zu beiden gleichzeitig!) und ist nur für den einzelnen hinterlegten Nutzer gültig (z.B. Einzel-Ticket). |

### Admin-Ticket-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `POST` | `/admin/travel/tickets` | Admin | Ticket erstellen (type=event/trip/user) |
| `PUT` | `/admin/travel/tickets/{id}` | Admin | Ticket aktualisieren |
| `DELETE` | `/admin/travel/tickets/{id}` | Admin | Ticket löschen |

**Request-Body (POST):**

```json
{
  "type": "event|trip|user",
  "event": "Event-UUID (bei type=event)",
  "trip": "Reise-UUID (bei type=trip)",
  "user": "User-UUID (bei type=user)",
  "qrcode": "QR-Code-Daten (optional)",
  "image": "Bild-URL (optional)"
}
```

**Request-Body (PUT):** gleiche Felder optional, nur übergebene Felder werden aktualisiert.

### User-Ticket-Endpunkte (Self-Service)

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/trips/tickets/user` | JWT | Eigene persönliche Tickets auflisten |
| `POST` | `/trips/tickets/user` | JWT | Persönliches Ticket erstellen (optional mit `event`- oder `trip`-ID) |
| `PUT` | `/trips/tickets/user/{ticketId}` | JWT | Eigenes Ticket aktualisieren (optional `event`/`trip`-Verknüpfung ändern) |
| `DELETE` | `/trips/tickets/user/{ticketId}` | JWT | Eigenes Ticket löschen |

**Request-Body (POST/PUT):**

```json
{
  "qrcode": "QR-Code-Daten (optional)",
  "image": "Bild-URL (optional)",
  "event": "Event-UUID (optional, nur wenn mit Event verknüpft)",
  "trip": "Reise-UUID (optional, nur wenn mit Reise verknüpft)"
}
```

> `event` und `trip` dürfen nicht gleichzeitig gesetzt werden.

### Lese-Endpunkte (für Teilnehmer)

Gibt jeweils Gruppen-Tickets (admin) und persönliche User-Tickets (self-service)
des aktuellen Nutzers zurück.

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/trips/{id}/tickets` | JWT | Tickets einer Reise (type='trip' + eigene type='user' mit trip=ID) |
| `GET` | `/trips/events/{eventId}/tickets` | JWT | Tickets eines Events (type='event' + eigene type='user' mit event=ID) |

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/trips` | JWT | Paginierte Liste der eigenen Reisen |
| `POST` | `/trips` | JWT | Reise erstellen (Ersteller wird `leader`) |
| `GET` | `/trips/{id}` | JWT | Reisedetails (inkl. `forumId`, `forum`, `subscriptionCount`, `role`, `canEdit`) |
| `PATCH` | `/trips/{id}` | JWT | Reise bearbeiten (nur `leader`) |
| `DELETE` | `/trips/{id}` | JWT | Reise löschen (nur `leader`) |
| `GET` | `/trips/{id}/events` | JWT | Alle Events einer Reise (mit Teilnehmern) |
| `POST` | `/trips/{id}/events` | JWT | Event einer Reise hinzufügen (nur `leader`; `trip` zwingend) |
| `GET` | `/trips/{id}/events/{eventId}` | JWT | Event-Details (mit Teilnehmern) |
| `PATCH` | `/trips/{id}/events/{eventId}` | JWT | Reise-Event bearbeiten/Konversion (nur `leader`) |
| `DELETE` | `/trips/{id}/events/{eventId}` | JWT | Reise-Event löschen (nur `leader`) |
| `POST` | `/trips/{id}/events/{eventId}/participants` | JWT | Teilnehmer zu einem Reise-Event hinzufügen (nur `leader`) |
| `DELETE` | `/trips/{id}/events/{eventId}/participants/{userId}` | JWT | Teilnehmer aus einem Reise-Event entfernen (nur `leader`) |
| `GET` | `/trips/{id}/tickets` | JWT | Tickets einer Reise (Gruppen- + eigene User-Tickets) |
| `GET` | `/trips/{id}/accommodations` | JWT | Alle Unterkünfte einer Reise (mit Nutzern) |
| `POST` | `/trips/{id}/accommodations` | JWT | Unterkunft anlegen oder vorhandene verknüpfen (`accommodationId`), jeder Teilnehmer |
| `GET` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunfts-Details (mit Nutzern) |
| `PATCH` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunft bearbeiten (`leader`, Ersteller oder Admin) |
| `DELETE` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunft von der Reise lösen (nur `leader`) |
| `GET` | `/trips/accommodations` | JWT | Globaler Katalog (optional `?q=<name>`) |
| `DELETE` | `/trips/accommodations/{accommodationId}` | JWT | Unterkunft endgültig aus dem Katalog löschen (Ersteller/Admin) |
| `GET` | `/trips/{id}/participants` | JWT | Alle Teilnehmer einer Reise (inkl. `role`) |
| `POST` | `/trips/{id}/participants` | JWT | Teilnehmer hinzufügen (nur `leader`) |
| `DELETE` | `/trips/{id}/participants/{userId}` | JWT | Teilnehmer entfernen (nur `leader`; letzter `leader` geschützt) |
| `PUT` | `/trips/{id}/participants/{userId}/role` | JWT | Rolle setzen (`leader`/`participant`, nur `leader`) |
| `PUT` | `/trips/{id}/participants/{userId}/accommodation` | JWT | Unterkunft zuweisen/aufheben (`leader` für alle, Mitreisende nur sich selbst) |
| `GET` | `/trips/{id}/subscriptions` | JWT | Mit Reise verknüpfte Abos (nur bei Zugriff) |
| `GET` | `/trips/standaloneevents` | JWT | Standalone-Events des Nutzers (paginiert, mit Teilnehmern) |
| `POST` | `/trips/standaloneevents` | JWT | Standalone-Event erstellen (Ersteller wird Veranstalter) |
| `GET` | `/trips/standaloneevents/{eventId}` | JWT | Standalone-Event-Details (mit Teilnehmern) |
| `PATCH` | `/trips/standaloneevents/{eventId}` | JWT | Standalone-Event bearbeiten bzw. an Reise anhängen |
| `DELETE` | `/trips/standaloneevents/{eventId}` | JWT | Standalone-Event löschen (nur Veranstalter) |
| `POST` | `/trips/standaloneevents/{eventId}/participants` | JWT | Teilnehmer hinzufügen (nur Veranstalter) |
| `DELETE` | `/trips/standaloneevents/{eventId}/participants/{userId}` | JWT | Teilnehmer entfernen (nur Veranstalter) |
| `PUT` | `/trips/standaloneevents/{eventId}/participants/{userId}/role` | JWT | Veranstalter-Rolle setzen (nur Veranstalter) |
| `GET` | `/trips/events/{eventId}` | JWT | **Unified** Event-Details via ID (Standalone + Reise-Events) |
| `GET` | `/trips/events/{eventId}/tickets` | JWT | Tickets eines Events (Gruppen- + eigene User-Tickets) |
| `GET` | `/trips/tickets/user` | JWT | Eigene persönliche Tickets |
| `POST` | `/trips/tickets/user` | JWT | Persönliches Ticket erstellen (optional `event`/`trip`) |
| `PUT` | `/trips/tickets/user/{ticketId}` | JWT | Eigenes Ticket aktualisieren (optional `event`/`trip`) |
| `DELETE` | `/trips/tickets/user/{ticketId}` | JWT | Eigenes Ticket löschen |

## Reise-Trip Response (erweitert)

Die Response von `GET /trips` und `GET /trips/{id}` enthält zusätzliche Felder:

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| `forumId` | string\|null | ID des verknüpften Forums (falls vorhanden) |
| `forum` | object\|null | Kurzinfo des verknüpften Forums (`id`, `name`, `description`, `image`) |
| `subscriptionCount` | integer | Anzahl der mit dieser Reise verknüpften Abos |

## City-Slug (Externe Daten)

Jede Unterkunft und jedes Event kann optional einen `citySlug` speichern.
Der Slug entspricht dem InfraNode-Stadt-Slug (z.B. `berlin`, `muenchen`)
und ermöglicht es Clients, direkt die passenden externen Daten
(Wetter, Luftqualität etc.) über `/external-data/weather?city_slug=<slug>` abzufragen.

- **Unterkünfte:** Jede Unterkunft hat ihren eigenen Slug (unterschiedliche Städte pro Mitreisendem möglich)
- **Events:** Events haben ihren eigenen Slug (z.B. bei mehrtägigen Reisen mit Städtewechsel)
- **Reisen:** Reisen haben keinen Slug (da Unterkünfte in verschiedenen Städten liegen können)
- **NULL:** Kein unterstützter Slug (Ausland, unbekannte Stadt) – Clients müssen Koordinaten verwenden
- **Vergeben:** Über Admin Dashboard beim Erstellen/Bearbeiten von Unterkünften und Events

## Unified Event Endpoint

`GET /trips/events/{eventId}` ist ein neuer Endpunkt, der Event-Details
unabhängig vom Kontext liefert:

- **Standalone-Event:** Der Nutzer muss in `EventRelation` eingetragen sein.
- **Reise-Event:** Der Nutzer muss Teilnehmer der zugehörigen Reise sein.
- Die Erkennung erfolgt automatisch anhand des `trip`-Feldes des Events.

## Reise-Forum-Verknüpfung

Ein Forum kann mit einer Reise verknüpft werden (über `TravelTrip.forumId`).
Wenn verknüpft:
- Alle Teilnehmer der Reise werden automatisch Mitglieder des Forums.
- Das Forum wird in der öffentlichen Foren-Liste **ausgeblendet**.
- Der Client zeigt einen "Forum"-Tab in der Reise-Detailansicht an.
- Die Forum-Inhalte werden über die bestehenden Forum-Endpunkte geladen.

## Reise-Abo-Verknüpfung

Abonnements können mit einer Reise verknüpft werden (über
`TravelTripSubscription`-Junction-Tabelle). Wenn verknüpft:
- Der Client zeigt einen "Zahlungen"-Tab an, sofern der Nutzer
  bei mindestens einem verknüpften Abo in `SubscriptionRelation` steht.
- `GET /trips/{id}/subscriptions` filtert automatisch nur die Abos,
  auf die der Nutzer Zugriff hat.

## Standalone-Events

Standalone-Events sind `TravelEvent`-Einträge ohne Reise-Bezug (`trip IS NULL`).
Sie werden unter `/trips/standaloneevents` abgerufen.

## Datenbank-Kompatibilität

Die Tabelle `TravelRelation` nutzt abweichende Spaltennamen:
- `userid` (statt `userId`)
- `tripid` (statt `tripId`)

Die Tabelle `TravelEvent` referenziert den Trip über das Feld `trip`
(entspricht `TravelTrip.id`). Bei Standalone-Events ist `trip` auf `NULL`
gesetzt.

Die Tabelle `TravelAccommodation` ist der globale Katalog. Sie wird über die
Junction-Tabelle `TravelAccommodationTrip` (`tripid`, `accommodationId`) mit
Reisen verknüpft und über `TravelRelation.accommodation` einzelnen Nutzern
zugeordnet. Die optionale Spalte `TravelAccommodation.tripId` bleibt nur für
Bestandsunterkünfte erhalten.

Die Tabelle `EventRelation` verknüpft Nutzer mit `TravelEvent.ID` und wird
sowohl für Reise-Events als auch für Standalone-Events genutzt.

## Planungsreisen (Vorbereitung)

Eine Reise kann als **Planungsreise** angelegt werden (`TravelTrip.state =
'planning'`). Sie durchläuft drei feste Kernphasen, die serverseitig als
Planungsthemen (`TravelPlanTopic.topic`) abgebildet werden:

| Topic | Phase |
|-------|-------|
| `participants` | Wann und wer? (Datum und Teilnehmende) |
| `travel` | Wo und wie? (Anreise, Abreise und Unterkunft) |
| `program` | Was machen wir? (Tagesprogramm und Events) |

Jedes Thema besitzt einen persistierten Status (`pending`, `in_progress`,
`completed`, `skipped`). Das Überspringen einer Phase setzt das Thema auf
`skipped`. Bestehende Reisen erhalten per Default den Zustand `active` und
bleiben unverändert.

**Wichtige Trennung:** Planungsteilnehmer (`TravelPlanMember`) sind unabhängig
von der operativen Teilnehmerliste (`TravelRelation`). Wer nur mitplant, erhält
dadurch keinen Zugriff auf aktive Reise-Events, Tickets oder Unterkünfte.
Planungsvorschläge werden getrennt von den endgültigen Reise-Objekten
gespeichert und erst bei der Aktivierung übernommen:

| Planungsdaten | Tabelle(n) | Überführung bei Aktivierung |
|---------------|-----------|------------------------------|
| Terminoptionen + Rückmeldungen | `TravelPlanDateOption`, `TravelPlanDateResponse` | `isFinal`-Option → `TravelTrip`-Zeitfelder |
| Transportpräferenzen | `TravelPlanTransport` | keine operative Entsprechung (nur Planungshistorie) |
| Unterkunftsoptionen | `TravelPlanAccommodationOption` | `isSelected`-Option → `TravelAccommodationTrip` inkl. `pricePerPersonPerNight`/`currency` |
| Tagesprogramm-Vorschläge | `TravelPlanEvent`, `TravelPlanEventInterest` | `isConfirmed`-Vorschläge → `TravelEvent` (Referenz in `confirmedEventId`) |

Der Unterkunftspreis ist reise-spezifisch: Er wird in der Planungsoption
festgehalten und bei der Aktivierung in `TravelAccommodationTrip` übernommen,
damit er dauerhaft in der operativen Reisedarstellung erhalten bleibt. Der
globale Katalog `TravelAccommodation` bleibt preisfrei.

> Das Schema wurde mit der Migration
> `database/migrations/20260930000000_travel_planning.sql` ergänzt (Phase 1,
> additiv/rückbaubar). API-Endpunkte, Policies und Aktivierungslogik folgen in
> separaten Phasen.

## Moderation

Reisen, Reise-Events, Unterkünfte und Tickets können über das
Melde- und Anfragensystem gemeldet werden. Für kollaborative Objekte
(Reisen, Events, Unterkünfte) wird der erste Teilnehmer aus der jeweiligen
Join-Tabelle als Eigentümer verwendet.

| objectType | Beschreibung | Eigentümer |
|------------|-------------|------------|
| `travel_trip` | Reise | Erster Teilnehmer (`TravelRelation`) |
| `travel_event` | Reise-Event | Erster Teilnehmer (`EventRelation`) |
| `travel_accommodation` | Unterkunft | Erster Teilnehmer (`TravelRelation`) |
| `travel_ticket` | Reise-Ticket | `user`-Feld des Tickets |

Details siehe `docs/moderation-requests/readme.md`.
