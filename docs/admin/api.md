# Admin-JSON-API (`/admin-api/*`)

Die Admin-JSON-API stellt alle Funktionen des Admin-Dashboards als maschinenlesbare
REST-API für den Flutter-Client bereit. Sie ist vollständig getrennt vom
session-basierten HTML-Dashboard (`/admin/*`) und verwendet JWT-Authentifizierung.

## Authentifizierung & Sicherheit

Es gibt zwei Autorisierungsstufen:

| Stufe | Token | Zweck |
|-------|-------|-------|
| **Lesen** | Normaler Access-Token (JWT) mit `isAdmin = true` | Listen und Detailansichten |
| **Schreiben** | Kurzlebiger Step-up-Admin-Token (`scope = 'admin'`) | Alle mutierenden Aktionen |

### Step-up-Flow

Mutierende Aktionen erfordern eine **erneute OTP-Bestätigung** („Step-up"),
damit ein gestohlener Alltags-Token keine Admin-Rechte erbt:

1. `POST /admin-api/auth/otp/request` – sendet einen 6-stelligen Code an die
   E-Mail des angemeldeten Admins (aus dem JWT ermittelt, kein freies
   E-Mail-Feld → keine Enumeration). Rate-Limit: 3 Anfragen/Minute.
2. `POST /admin-api/auth/otp/verify` mit `{ "code": "482731" }` – verifiziert
   den Code und liefert `{ access_token, expires_in: 1200, token_type }`.

Der Step-up-Admin-Token:

- ist **20 Minuten** gültig,
- ist **nicht refreshbar** (kein Refresh-Token, kein Verlängern),
- trägt den Claim `scope: 'admin'` und ist damit klar vom normalen
  Access-Token unterscheidbar,
- wird erst nach erneuter DB-Prüfung (`isAdmin = true`) ausgestellt.

HTTPS ist für den gesamten Namespace erzwungen (`RequireHttpsMiddleware`).

## Endpoints

### Auth (Step-up)

| Methode | Pfad | Token |
|---------|------|-------|
| POST | `/admin-api/auth/otp/request` | BearerAuth (isAdmin) |
| POST | `/admin-api/auth/otp/verify` | BearerAuth (isAdmin) |

### Lesen (BearerAuth mit isAdmin)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/admin-api/dashboard` | Kennzahlen (Nutzer, Reisen, offene Anfragen, Cache) |
| GET | `/admin-api/users?q=` | Nutzerliste/-suche |
| GET | `/admin-api/forums` | Forenliste inkl. Mitgliederzahl |
| GET | `/admin-api/arcade` | Arcade-Spiele (auch inaktive) |
| GET | `/admin-api/recipes` | Rezepte (inkl. Entwürfe) |
| GET | `/admin-api/polls` | Umfragen-Übersicht |
| GET | `/admin-api/polls/{id}` | Umfrage-Details (Fragen, Optionen, Stimmen, Antworten) |
| GET | `/admin-api/travel` | Reisen & Events |
| GET | `/admin-api/travel/trips/{id}` | Reise-Details (Teilnehmer, Unterkünfte, Events, Forum, Abos, Chat) |
| GET | `/admin-api/travel/events/{id}` | Event-Details (Teilnehmer, Chat) |
| GET | `/admin-api/explore/submissions` | Orte-Einreichungen (Statusfilter, Pagination, Zähler) |
| GET | `/admin-api/explore/submissions/{id}` | Einreichungs-Details |
| GET | `/admin-api/subscriptions` | Abo-Liste |
| GET | `/admin-api/subscriptions/{id}` | Abo-Details (Teilnehmer) |
| GET | `/admin-api/moderation-requests` | Moderations-Anfragen (Filter, Pagination, Zähler) |
| GET | `/admin-api/moderation-requests/{id}` | Anfrage-Details |
| GET | `/admin-api/notifications` | Entitäten für Benachrichtigungs-Vorlagen |
| GET | `/admin-api/external-data-cache?data_type=` | Cache-Einträge |

### Schreiben (AdminStepUpToken)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| POST | `/admin-api/travel/trips` | Reise anlegen |
| PUT | `/admin-api/travel/trips/{id}` | Reise bearbeiten |
| DELETE | `/admin-api/travel/trips/{id}` | Reise löschen |
| POST | `/admin-api/travel/trips/{id}/chat` | Gruppenchat erstellen |
| DELETE | `/admin-api/travel/trips/{id}/chat` | Gruppenchat löschen |
| PATCH | `/admin-api/travel/trips/{id}/chat` | Chat-Icon setzen/entfernen |
| POST | `/admin-api/travel/trips/{id}/participants` | Teilnehmer hinzufügen |
| DELETE | `/admin-api/travel/trips/{id}/participants/{userId}` | Teilnehmer entfernen |
| PUT | `/admin-api/travel/trips/{id}/participants/{userId}/role` | Reiseleiter-Rolle setzen |
| PUT | `/admin-api/travel/trips/{id}/participants/{userId}/accommodation` | Unterkunftszuweisung ändern |
| PUT | `/admin-api/travel/trips/{id}/forum` | Forum verknüpfen/trennen |
| POST | `/admin-api/travel/trips/{id}/subscriptions` | Abo verknüpfen |
| DELETE | `/admin-api/travel/trips/{id}/subscriptions/{subscriptionId}` | Abo trennen |
| POST | `/admin-api/travel/trips/{id}/accommodations` | Unterkunft erstellen |
| PUT | `/admin-api/travel/trips/{id}/accommodations/{accId}` | Unterkunft bearbeiten |
| DELETE | `/admin-api/travel/trips/{id}/accommodations/{accId}` | Unterkunft löschen |
| DELETE | `/admin-api/travel/accommodations/{id}` | Katalog-Unterkunft löschen |
| POST | `/admin-api/travel/events` | Event anlegen |
| PUT | `/admin-api/travel/events/{id}` | Event bearbeiten |
| DELETE | `/admin-api/travel/events/{id}` | Event löschen |
| POST | `/admin-api/travel/events/{id}/chat` | Gruppenchat erstellen |
| DELETE | `/admin-api/travel/events/{id}/chat` | Gruppenchat löschen |
| PATCH | `/admin-api/travel/events/{id}/chat` | Chat-Icon setzen/entfernen |
| POST | `/admin-api/travel/events/{id}/participants` | Teilnehmer hinzufügen |
| DELETE | `/admin-api/travel/events/{id}/participants/{userId}` | Teilnehmer entfernen |
| PUT | `/admin-api/travel/events/{id}/participants/{userId}/role` | Veranstalter-Rolle setzen |
| PATCH | `/admin-api/travel/events/{id}/trip` | Event zwischen Reise und Standalone konvertieren |
| POST | `/admin-api/travel/tickets` | Ticket anlegen |
| PUT | `/admin-api/travel/tickets/{id}` | Ticket bearbeiten |
| DELETE | `/admin-api/travel/tickets/{id}` | Ticket löschen |
| POST | `/admin-api/forums` | Forum anlegen |
| PUT | `/admin-api/forums/{id}` | Forum bearbeiten |
| DELETE | `/admin-api/forums/{id}` | Forum löschen |
| POST | `/admin-api/subscriptions` | Abo anlegen |
| PUT | `/admin-api/subscriptions/{id}` | Abo bearbeiten |
| DELETE | `/admin-api/subscriptions/{id}` | Abo löschen |
| POST | `/admin-api/subscriptions/{id}/participants` | Abo-Teilnehmer hinzufügen |
| PUT | `/admin-api/subscriptions/{id}/participants/{participantId}` | Bezahlstatus ändern |
| DELETE | `/admin-api/subscriptions/{id}/participants/{participantId}` | Abo-Teilnehmer entfernen |
| POST | `/admin-api/polls/{id}/close` | Umfrage schließen |
| DELETE | `/admin-api/polls/{id}` | Umfrage löschen |
| POST | `/admin-api/explore/submissions/{id}/approve` | Einreichung freigeben |
| POST | `/admin-api/explore/submissions/{id}/reject` | Einreichung ablehnen |
| POST | `/admin-api/moderation-requests/{id}/update` | Status + Admin-Kommentar setzen |
| POST | `/admin-api/notifications/send` | Test-/Rundschreiben-Benachrichtigung senden |
| POST | `/admin-api/arcade` | Arcade-Spiel anlegen |
| PUT | `/admin-api/arcade/{id}` | Arcade-Spiel bearbeiten |
| DELETE | `/admin-api/arcade/{id}` | Arcade-Spiel löschen |
| DELETE | `/admin-api/recipes/{id}` | Rezept löschen |
| DELETE | `/admin-api/external-data-cache/{id}` | Einzelnen Cache-Eintrag löschen |
| DELETE | `/admin-api/external-data-cache?data_type=` | Cache leeren |

## Audit-Log

Jede **mutierende** Aktion erzeugt einen Eintrag in der Tabelle `AdminAuditLog`:

| Feld | Beschreibung |
|------|--------------|
| `adminId` | ID des handelnden Admins |
| `action` | Aktion (z. B. `create`, `delete`, `approve`, `reject`) |
| `entityType` | Entitätstyp (z. B. `trip`, `forum`, `submission`) |
| `entityId` | Betroffene Entitäts-ID |
| `ipAddress` | Client-IP (unterstützt `X-Forwarded-For`) |
| `userAgent` | User-Agent des Clients |
| `createdAt` | Zeitstempel (UTC) |

Lesende Zugriffe werden bewusst nicht protokolliert.

## Fehlerantworten

Alle Fehler folgen dem einheitlichen Schema:

```json
{ "error": "error_code" }
```

Häufige Fehlercodes: `unauthorized` (401), `token_invalid` (401),
`forbidden` (403), `admin_step_up_required` (403), `invalid_or_expired_code`
(400), `too_many_requests` (429).

## DTO-Referenz

Die vollständige, maschinenlesbare Definition aller Antwort-Schemas
(`AdminUser`, `AdminTrip`, `AdminTripDetail`, `AdminEvent`, `AdminEventDetail`,
`AdminForum`, `AdminPoll`, `AdminPollDetail`, `AdminSubscription`,
`AdminSubmission`, `AdminModerationRequest`, `AdminAuditLogEntry`, …) liegt in
`openapi.yaml` unter dem Tag `Admin API`.
