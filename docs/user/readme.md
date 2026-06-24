# Benutzerprofil (User)

Die User-Module-API ermöglicht den Zugriff auf Benutzerprofildaten,
aufgeschlüsselt nach Basis-Profil, Social-Media-Handles und Kontaktinformationen,
sowie die Bearbeitung des eigenen Profils.

## Authentifizierung

Alle Endpunkte erfordern einen gültigen **Access-Token (JWT)** im `Authorization`-Header:

```
Authorization: Bearer <access_token>
```

## Endpunkte – Lesen

### Alle Benutzer auflisten

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user` | Liste aller Benutzer (gefiltert nach Sichtbarkeit) |

Gibt ein Array aller Benutzer zurück. Jeder Eintrag enthält die Basis-Profildaten,
gefiltert nach den Sichtbarkeitseinstellungen des jeweiligen Benutzers
(siehe [Sichtbarkeitssystem](#sichtbarkeitssystem)).

### Eigene Profildaten (`/me`)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/me` | Vollständiges Profil (Basis + Social + Kontakt) mit Sichtbarkeitseinstellungen |
| `GET` | `/user/me/base` | Nur Basis-Profil (User-Tabelle) |
| `GET` | `/user/me/social` | Nur Social-Media-Handles (SocialInfo-Tabelle) |
| `GET` | `/user/me/contact` | Nur Kontaktinformationen (ContactInfo-Tabelle) |

Diese Endpunkte geben **alle Felder** inklusive der Visibility-Werte zurück.
Der angemeldete Nutzer sieht seine eigenen Einstellungen.

### Sichtbarkeitseinstellungen ändern

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PUT` | `/user/me/visibility` | Sichtbarkeit beliebig vieler Felder gleichzeitig setzen |

Der Request Body kann eine beliebige Kombination der folgenden Felder enthalten:

**User-Felder:** `emailVisibility`, `birthdayVisibility`

**ContactInfo-Felder:** `discordVisibility`, `fluxerVisibility`, `matrixVisibility`, `signalVisibility`, `whatsappVisibility`

**SocialInfo-Felder:** `unsplashVisibility`, `instagramVisibility`, `mastodonVisibility`, `pixelfedVisibility`, `blueskyVisibility`, `youtubeVisibility`, `twitchVisibility`

Erlaubte Werte:
| Wert | Bedeutung |
|------|-----------|
| `0` | Niemand außer dem Eigentümer selbst |
| `1` | Jeder eingeloggte Nutzer (Standard) |
| `2` | Nur enge Freunde |

### Fremde Profildaten (`/{userId}`)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/{userId}` | Vollständiges Profil eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/base` | Basis-Profil eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/social` | Social-Handles eines anderen Nutzers (gefiltert) |
| `GET` | `/user/{userId}/contact` | Kontaktdaten eines anderen Nutzers (gefiltert) |

Diese Endpunkte beachten die **Sichtbarkeitseinstellungen** des angefragten Nutzers.
Nicht sichtbare Felder werden komplett weggelassen (nicht als `null` gesendet).

## Endpunkte – Schreiben (Profil bearbeiten)

### Profil-Update (Mehrere Felder gleichzeitig)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PUT` | `/user/me/profile` | Beliebig viele Profilfelder aktualisieren |

Der Request Body kann eine beliebige Kombination der folgenden Felder enthalten.
Nur die gesendeten Felder werden aktualisiert.

**User-Felder:** `displayName`, `birthday`, `image`

**ContactInfo-Felder:** `discordHandle`, `fluxerHandle`, `signalNumber`, `whatsappNumber`, `matrixUser`, `matrixHomeserver`

**SocialInfo-Felder:** `unsplashHandle`, `instagramHandle`, `blueskyHandle`, `youtubeHandle`, `twitchHandle`, `mastodonUser` + `mastodonServer`, `pixelfedUser` + `pixelfedServer`

**Validierungsregeln:**

| Feld | Regel |
|------|-------|
| `displayName` | Darf nicht leer sein |
| `birthday` | `YYYY-MM-DD` oder `null` |
| `image` | Base64-codiertes Bild, `null` zum Entfernen (siehe [Profilbild](#profilbild)) |
| `signalNumber` | Format `username.00` (Punkt + 2 Ziffern am Ende) |
| `whatsappNumber` | Beginnt mit `+`, gefolgt von Ziffern |
| `matrixUser` | Kein `@`, kein `:` |
| `matrixHomeserver` | Domain-Format (`server.de`) |
| `unsplashHandle` | Kein `@` |
| `instagramHandle` | Kein `@` |
| `mastodonUser` | Kein `@`, kein `:` |
| `mastodonServer` | Domain-Format (`server.de`) |
| `pixelfedUser` | Kein `@`, kein `:` |
| `pixelfedServer` | Domain-Format (`server.de`) |
| `blueskyHandle` | Kein `@`, Domain-Format (`domain.de`) |
| `youtubeHandle` | Kein `@` |
| `twitchHandle` | Kein `@` |

**Response:** Die vollständigen aktualisierten Profildaten (gleiches Format wie `GET /user/me`).

### E-Mail-Änderung (mit OTP-Bestätigung)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/user/me/email/request` | OTP an neue Adresse senden |
| `POST` | `/user/me/email/verify` | OTP bestätigen & E-Mail-Update ausführen |

**Ablauf:**
1. `POST /user/me/email/request` mit `{ "newEmail": "neu@example.com" }`
   - Prüfung: Format, bereits vergeben, Rate-Limit
   - 6-stelliger Code wird an die **neue** Adresse gesendet
2. `POST /user/me/email/verify` mit `{ "code": "123456", "newEmail": "neu@example.com" }`
   - Code verifizieren
   - E-Mail in DB aktualisieren
   - **Alle Refresh-Tokens werden gelöscht** (Neu-Login erforderlich)
   - Benachrichtigung an alte E-Mail-Adresse
   - Admin-Benachrichtigung

### Discord-Verknüpfung ändern (mit OAuth2)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/user/me/discord/start` | Discord-OAuth-URL anfordern |
| `GET` | `/user/me/discord/callback` | Discord-Redirect (Browser) – zeigt Pairing-Code |
| `POST` | `/user/me/discord/verify` | Pairing-Code bestätigen & Discord-ID aktualisieren |

**Ablauf:**
1. `POST /user/me/discord/start` → erhält Discord-OAuth-URL
2. Nutzer im Browser zu Discord weiterleiten, autorisieren
3. Discord redirectet zu `/user/me/discord/callback` → 6-stelligen Code merken
4. `POST /user/me/discord/verify` mit `{ "code": "123456" }` → Discord-ID wird in DB aktualisiert
5. Benachrichtigung an hinterlegte E-Mail + Admin-Benachrichtigung

**Hinweis:** Die Redirect-URI `/api/v2/user/me/discord/callback` muss im Discord Developer Portal
als zusätzliche Redirect-URI eingetragen werden (neben der Login-URI).

### Profilbild

Das Profilbild wird als Base64-codierter String über das `image` Feld im `PUT /user/me/endpoint` übermittelt.

**Anforderungen:**

| Eigenschaft | Limit |
|-------------|-------|
| Dateigröße (Base64-decodiert) | Max. 200 KB |
| Erlaubte Formate | JPEG, PNG, WebP |
| Max. Breite | 1000 Pixel |
| Max. Höhe | 1000 Pixel |

**Beispiel-Request:**
```json
{
  "image": "/9j/4AAQSkZJRgABAQEASABIAAD..."
}
```

**Profilbild entfernen:**
```json
{
  "image": null
}
```

**Fehlercodes:**

| Code | Beschreibung |
|------|-------------|
| `invalid_image` | Ungültiges Bild oder leerer String |
| `invalid_image_encoding` | Base64-Dekodierung fehlgeschlagen |
| `image_too_large` | Dateigröße überschreitet 200 KB |
| `invalid_image_format` | Datei ist kein gültiges Bild |
| `unsupported_image_format` | Format nicht erlaubt (nur JPEG, PNG, WebP) |
| `image_dimensions_too_large` | Abmessungen überschreiten 1000x1000 Pixel |

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

**Hinweis:** Bei Mastodon und Pixelfed wird der Handle im Format `user@server.de` in der Datenbank
gespeichert, aber in der API als zwei separate Felder (`mastodonUser` + `mastodonServer`,
`pixelfedUser` + `pixelfedServer`) ausgeliefert und entgegengenommen.

## Enge-Freunde-Beziehung (CloseFriend)

Die Tabelle `CloseFriend` speichert einseitige Beziehungen:
`CloseFriend { userId: A, friendId: B }` bedeutet, dass **A den B als engen Freund hinzugefügt hat**.
Dadurch darf **B** die auf Sichtbarkeit `2` gestellten Informationen von **A** sehen.

Die Beziehung ist **nicht gegenseitig**: Nur weil A den B hinzugefügt hat, hat A keine
erweiterten Rechte an Bs Informationen. B muss A ebenfalls hinzufügen, damit A Bs
level-2-Daten sehen kann.

## Datenbanktabellen

- **`User`** – Basis-Profil (E-Mail, Anzeigename, Geburtstag, Bild, Discord-ID, ...)
- **`SocialInfo`** – Social-Media-Handles (7 Plattformen)
- **`ContactInfo`** – Kontaktmöglichkeiten (Discord, Fluxer, Signal, WhatsApp, Matrix)
- **`CloseFriend`** – Enge-Freunde-Beziehungen für Sichtbarkeit Level 2
- **`OtpToken`** – 6-stellige Codes für Login und E-Mail-Änderung
- **`WebauthnChallenge`** – OAuth2-State-Speicher für Discord-Login und Discord-Relink
- **`RefreshToken` / `RefreshTokenFamily`** – Sitzungsverwaltung (werden bei E-Mail-Änderung gelöscht)
