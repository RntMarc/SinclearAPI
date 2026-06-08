# Berechtigungskonzept

## Rollen

| Rolle | Flag | Rechte |
|-------|------|--------|
| User | `isAdmin = 0` | Eigene Daten lesen/ändern |
| Admin | `isAdmin = 1` | Alle Daten, Admin-only-Ressourcen |

## Policy-System

Jede Ressource hat eine Policy-Klasse in `src/Security/Policy/`:

- `UserListPolicy` – Nutzer: nur eigene Daten (Admin: alle)
- `OwnerPolicy` – `userId`-basiert
- `CreatorPolicy` – `creatorId`-basiert, öffentliches Lesen
- `EventPolicy` – öffentliche Events + Creator + Permissions
- `AdminOnlyPolicy` – Travel, RSS, Subscriptions
- `AuthenticatedReadPolicy` – Lesen für alle Auth-User

Autorisierung erfolgt **nicht** in Controllern, sondern in `ResourceService` via Policy.

## Sensitive Felder

Folgende Felder werden nie an Clients gesendet:

- `passwordHash`
- `code` (OTP)
- `publicKey` (Passkeys)
- `token_hash` / `tokenHash`
- `challenge`

## IDOR-Schutz

Jeder `GET /{resource}/{id}` prüft `canView()`. Listen-Endpunkte filtern per `listFilters()` auf den aktuellen Nutzer (sofern nicht Admin).
