# Subscriptions API

## Überblick

Die Subscriptions-API ermöglicht es Nutzern, ihre Abonnements einzusehen und zu verwalten. Administratoren können über das Admin-Dashboard alle Abonnements im System verwalten.

## Datenstruktur

### Subscription
| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `id` | string (UUID) | Eindeutige Identifikationsnummer |
| `name` | string | Name des Abonnements |
| `billingPeriodStart` | date | Beginn des Abrechnungszeitraums |
| `billingPeriodEnd` | date | Ende des Abrechnungszeitraums |
| `basePrice` | number | Grundpreis des Abonnements |
| `role` | string | Rolle des Nutzers (`creator` oder `participant`) |
| `hasPaid` | boolean | Bezahltstatus des Nutzers |

### SubscriptionParticipant
| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `id` | string (UUID) | Eindeutige Identifikationsnummer |
| `userId` | string \| null | Nutzer-ID (null bei Nicht-Nutzern) |
| `userName` | string \| null | Name des Teilnehmers |
| `isUser` | boolean | Registrierter Nutzer oder nicht |
| `hasPaid` | boolean | Bezahltstatus |

## API-Endpunkte (JWT-authentifiziert)

### GET /subscriptions
Gibt alle Abonnements des authentifizierten Nutzers zurück (erstellt + als Teilnehmer).

**Antwort:**
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "Spotify",
      "billingPeriodStart": "2026-01-01",
      "billingPeriodEnd": "2026-12-31",
      "basePrice": 264.00,
      "role": "creator",
      "hasPaid": true
    }
  ]
}
```

### GET /subscriptions/{id}
Gibt die Details eines bestimmten Abonnements zurück. Der Nutzer muss beteiligt sein.

### GET /subscriptions/{id}/participants
Gibt alle Teilnehmer eines Abonnements zurück.

## Reise-verknüpfte Abos

Abonnements können mit einer Reise verknüpft werden (über die
`TravelTripSubscription`-Junction-Tabelle). Die Verknüpfung erfolgt über das
Admin-Dashboard in der Reise-Detailansicht.

**Verhalten:**
- `GET /trips/{id}/subscriptions` gibt nur die Abos zurück, bei denen der
  authentifizierte Nutzer in `SubscriptionRelation` eingetragen ist.
- Hat der Nutzer bei keinem verknüpften Abo Zugriff, bleibt der Tab
  "Zahlungen" im Client ausgeblendet.
- Die Abos erscheinen weiterhin auf dem normalen Abo-Screen des Nutzers.

## Admin-Dashboard Endpunkte (Session-authentifiziert)

### GET /admin/subscriptions
HTML-Übersicht aller Abonnements.

### GET /admin/subscriptions/json
JSON-Übersicht aller Abonnements (für AJAX).

### POST /admin/subscriptions
Neues Abo erstellen.

**Request Body:**
```json
{
  "name": "Netflix",
  "billingPeriodStart": "2026-01-01",
  "billingPeriodEnd": "2026-12-31",
  "basePrice": 192.00
}
```

### PUT /admin/subscriptions/{id}
Abo aktualisieren.

### DELETE /admin/subscriptions/{id}
Abo löschen.

### POST /admin/subscriptions/{id}/participants
Teilnehmer hinzufügen.

**Request Body:**
```json
{
  "userName": "Max Mustermann",
  "userId": "optional-uuid",
  "hasPaid": false
}
```

### DELETE /admin/subscriptions/{id}/participants/{participantId}
Teilnehmer entfernen.

## Berechtigungen

- **Öffentliche API:** Nur eigene Abonnements (erstellt + als Teilnehmer beteiligt)
- **Admin-Dashboard:** Alle Abonnements im System (nur Administratoren)
- **Teilnehmerverwaltung:** Nur über das Admin-Dashboard

## Technische Details

- **Repository:** `SubscriptionRepository.php`
- **Service:** `SubscriptionService.php`
- **Controller:** `SubscriptionController.php` (API) + `AdminController.php` (Dashboard)
- **Policy:** `SubscriptionPolicy.php`
- **Templates:** `templates/admin/subscriptions.php`, `templates/admin/subscription_detail.php`
