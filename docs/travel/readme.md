# Reisen (Travel)

Die Travel-Funktion erlaubt Nutzern das Verwalten und Abrufen von Reisen,
zugehörigen Events und Unterkünften. Jeder Nutzer sieht nur die Reisen,
bei denen er über die `TravelRelation`-Tabelle als Teilnehmer eingetragen ist.

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|-------------|
| `TravelTrip` | Reisedaten (Name, Beschreibung, Zeitraum) |
| `TravelEvent` | Ereignisse innerhalb einer Reise |
| `TravelEventTicket` | Eintrittskarten für Events |
| `TravelAccommodation` | Unterkünfte (Hotels, Ferienwohnungen, etc.) |
| `TravelRelation` | Verknüpfung von Nutzern mit Reisen und Unterkünften |

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

Sobald die Trip-Teilnahme bestätigt ist, werden alle zugehörigen Events
und Unterkünfte uneingeschränkt ausgegeben (nicht nur die eigenen).

## API-Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `GET` | `/trips` | JWT | Paginierte Liste der eigenen Reisen |
| `GET` | `/trips/{id}` | JWT | Reisedetails |
| `GET` | `/trips/{id}/events` | JWT | Alle Events einer Reise |
| `GET` | `/trips/{id}/events/{eventId}` | JWT | Event-Details |
| `GET` | `/trips/{id}/accommodations` | JWT | Alle Unterkünfte einer Reise |
| `GET` | `/trips/{id}/accommodations/{accommodationId}` | JWT | Unterkunfts-Details |
| `GET` | `/trips/{id}/participants` | JWT | Alle Teilnehmer einer Reise |

## Datenbank-Kompatibilität

Die Tabelle `TravelRelation` nutzt abweichende Spaltennamen:
- `userid` (statt `userId`)
- `tripid` (statt `tripId`)

Die Tabelle `TravelEvent` referenziert den Trip über das Feld `trip`
(entspricht `TravelTrip.id`).

Die Tabelle `TravelAccommodation` wird über `TravelRelation.accommodation`
mit den Nutzern und damit der Reise verknüpft.
