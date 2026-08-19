# Reisen (Travel)

Die Travel-Funktion erlaubt Nutzern das Verwalten und Abrufen von Reisen,
zugehörigen Events und Unterkünften. Jeder Nutzer sieht nur die Reisen,
bei denen er über die `TravelRelation`-Tabelle als Teilnehmer eingetragen ist.

> **Hinweis zu Zeitangaben:** Alle Datum- und Zeitangaben (DateTime) werden ausschließlich in UTC gespeichert und von der API in UTC ausgegeben. Das Format ist `YYYY-MM-DD HH:MM:SS` (24h, ohne Millisekunden, ohne Zeitzonenindikatoren). Clients sind eigenständig für die Konvertierung lokaler Zeitangaben nach UTC vor dem Senden und von UTC in die lokale Zeitzone bei der Anzeige verantwortlich. Die API führt keine Zeitzonenkonvertierung durch.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `TravelTrip` | Reisedaten (Name, Beschreibung, Zeitraum) |
| `TravelEvent` | Ereignisse (Reise-Events + Standalone-Events via `trip IS NULL`) |
| `TravelEventTicket` | Tickets für Reisen, Events oder persönliche Nutzer-Tickets |
| `TravelAccommodation` | Unterkünfte (Hotels, Ferienwohnungen, etc.) |
| `TravelRelation` | Verknüpfung von Nutzern mit Reisen und Unterkünften |
| `EventRelation` | Teilnehmer an Events (sowohl Reise- als auch Standalone) |
| `TravelChat` | Verknüpfung von Gruppenchats mit Reisen oder Events |

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
| `GET /trips/{id}/accommodations/{accommodationId}` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/participants` | Nutzer muss Teilnehmer der Reise sein |
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

## Unterkunft-Zuordnung (TravelRelation)

Jede `TravelAccommodation` kann mehreren Nutzern zugeordnet sein (über
`TravelRelation.accommodation`). Die zugeordneten Nutzer werden als
`users`-Array im Response mitgeliefert:

```json
{
  "data": {
    "ID": "...",
    "name": "Hotel Sonnenschein",
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
| `GET` | `/trips/{id}` | JWT | Reisedetails (inkl. `forumId`, `forum`, `subscriptionCount`) |
| `GET` | `/trips/{id}/events` | JWT | Alle Events einer Reise (mit Teilnehmern) |
| `GET` | `/trips/{id}/events/{eventId}` | JWT | Event-Details (mit Teilnehmern) |
| `GET` | `/trips/{id}/tickets` | JWT | Tickets einer Reise (Gruppen- + eigene User-Tickets) |
| `GET` | `/trips/{id}/accommodations` | JWT | Alle Unterkünfte einer Reise (mit Nutzern) |
| `GET` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunfts-Details (mit Nutzern) |
| `GET` | `/trips/{id}/participants` | JWT | Alle Teilnehmer einer Reise |
| `GET` | `/trips/{id}/subscriptions` | JWT | Mit Reise verknüpfte Abos (nur bei Zugriff) |
| `GET` | `/trips/standaloneevents` | JWT | Standalone-Events des Nutzers (paginiert, mit Teilnehmern) |
| `GET` | `/trips/standaloneevents/{eventId}` | JWT | Standalone-Event-Details (mit Teilnehmern) |
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

Die Tabelle `TravelAccommodation` wird über `TravelRelation.accommodation`
mit den Nutzern und damit der Reise verknüpft.

Die Tabelle `EventRelation` verknüpft Nutzer mit `TravelEvent.ID` und wird
sowohl für Reise-Events als auch für Standalone-Events genutzt.

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
