# Benutzerprofil (User)

Die User-Module-API ermöglicht den Zugriff auf Benutzerprofildaten,
aufgeschlüsselt nach Basis-Profil, Social-Media-Handles und Kontaktinformationen.

## Authentifizierung

Alle Endpunkte erfordern einen gültigen **Access-Token (JWT)** im `Authorization`-Header:

```
Authorization: Bearer <access_token>
```

## Endpunkte

### Eigene Profildaten (`/me`)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/me` | Vollständiges Profil (Basis + Social + Kontakt) mit Sichtbarkeitseinstellungen |
| `GET` | `/user/me/base` | Nur Basis-Profil (User-Tabelle) |
| `GET` | `/user/me/social` | Nur Social-Media-Handles (SocialInfo-Tabelle) |
| `GET` | `/user/me/contact` | Nur Kontaktinformationen (ContactInfo-Tabelle) |

Diese Endpunkte geben **alle Felder** inklusive der Visibility-Werte zurück.
Der angemeldete Nutzer sieht seine eigenen Einstellungen.

### Fremde Profildaten (`/{userId}`)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/{userId}` | Vollständiges Profil eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/base` | Basis-Profil eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/social` | Social-Handles eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/contact` | Kontaktdaten eines anderen Nutzers (gefiltert) |

Diese Endpunkte beachten die **Sichtbarkeitseinstellungen** des angefragten Nutzers.
Nicht sichtbare Felder werden komplett weggelassen (nicht als `null` gesendet).

## Sichtbarkeitssystem

Jedes Informationselement hat einen Sichtbarkeitswert (`Visibility`):

| Wert | Bedeutung |
|------|-----------|
| `0` | Niemand außer dem Eigentümer selbst darf diese Information sehen |
| `1` | Jeder eingeloggte Nutzer darf diese Information sehen (Standard) |
| `2` | Nur enge Freunde des Eigentümers dürfen diese Information sehen |

### Felder mit Sichtbarkeitssteuerung

**User-Tabelle:**
- `email` – gesteuert durch `emailVisibility`
- `birthday` – gesteuert durch `birthdayVisibility`

**SocialInfo-Tabelle:** Jeder Handle hat eine eigene `*Visibility`-Spalte.

**ContactInfo-Tabelle:** Jeder Kontaktwert hat eine eigene `*Visibility`-Spalte.
Die Matrix-Felder (`matrixUser`, `matrixHomeserver`) werden gemeinsam durch `matrixVisibility` gesteuert.

## Enge-Freunde-Beziehung (CloseFriend)

Die Tabelle `CloseFriend` speichert einseitige Beziehungen:
`CloseFriend { userId: A, friendId: B }` bedeutet, dass **A den B als engen Freund hinzugefügt hat**.
Dadurch darf **B** die auf Sichtbarkeit `2` gestellten Informationen von **A** sehen.

Die Beziehung ist **nicht gegenseitig**: Nur weil A den B hinzugefügt hat, hat A keine
erweiterten Rechte an Bs Informationen. B muss A ebenfalls hinzufügen, damit A Bs
level-2-Daten sehen kann.

## Datenbanktabellen

- **`User`** – Basis-Profil (E-Mail, Anzeigename, Geburtstag, Bild, ...)
- **`SocialInfo`** – Social-Media-Handles (7 Plattformen)
- **`ContactInfo`** – Kontaktmöglichkeiten (Discord, Fluxer, Signal, WhatsApp, Matrix)
- **`CloseFriend`** – Enge-Freunde-Beziehungen für Sichtbarkeit Level 2
