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
| `TravelEventTicket` | Eintrittskarten für Events |
| `TravelAccommodation` | Unterkünfte (Hotels, Ferienwohnungen, etc.) |
| `TravelRelation` | Verknüpfung von Nutzern mit Reisen und Unterkünften |
| `EventRelation` | Teilnehmer an Events (sowohl Reise- als auch Standalone) |

## Autorisierungs-Logik

Alle Endpunkte benötigen einen gültigen JWT (Bearer Token).

| Endpunkt | Zugriffsprüfung |
|----------|----------------|
| `GET /trips` | Nur Reisen, bei denen der Nutzer in `TravelRelation` steht |
| `GET /trips/{id}` | Nutzer muss Teilnehmer der Reise sein → sonst `403` |
| `GET /trips/{id}/events` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/events/{eventId}` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/accommodations` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/accommodations/{accommodationId}` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/{id}/participants` | Nutzer muss Teilnehmer der Reise sein |
| `GET /trips/standaloneevents` | Nur Events, bei denen Nutzer in `EventRelation` steht |
| `GET /trips/standaloneevents/{eventId}` | Nutzer muss in `EventRelation` sein → sonst `404` |

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

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/trips` | JWT | Paginierte Liste der eigenen Reisen |
| `GET` | `/trips/{id}` | JWT | Reisedetails |
| `GET` | `/trips/{id}/events` | JWT | Alle Events einer Reise (mit Teilnehmern) |
| `GET` | `/trips/{id}/events/{eventId}` | JWT | Event-Details (mit Teilnehmern) |
| `GET` | `/trips/{id}/accommodations` | JWT | Alle Unterkünfte einer Reise (mit Nutzern) |
| `GET` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunfts-Details (mit Nutzern) |
| `GET` | `/trips/{id}/participants` | JWT | Alle Teilnehmer einer Reise |
| `GET` | `/trips/standaloneevents` | JWT | Standalone-Events des Nutzers (paginiert, mit Teilnehmern) |
| `GET` | `/trips/standaloneevents/{eventId}` | JWT | Standalone-Event-Details (mit Teilnehmern) |

## Standalone-Events

Standalone-Events sind `TravelEvent`-Einträge ohne Reise-Bezug (`trip IS NULL`).
Sie werden unter `/trips/standaloneevents` abgerufen.

- **Listen-Endpunkt:** Nur Events, bei denen der Nutzer via `EventRelation`
  als Teilnehmer eingetragen ist.
- **Detail-Endpunkt:** Nutzer muss in `EventRelation` sein → sonst `404`.
- Die Response enthält ebenfalls das `participants`-Array mit allen Teilnehmern.

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
